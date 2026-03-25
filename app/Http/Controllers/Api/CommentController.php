<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\Post;
use App\Models\Report;
use App\Services\Firebase\FirebaseLiveUpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CommentController extends Controller
{
    protected FirebaseLiveUpdateService $firebaseLiveUpdate;

    public function __construct(FirebaseLiveUpdateService $firebaseLiveUpdate)
    {
        $this->firebaseLiveUpdate = $firebaseLiveUpdate;
    }
    /**
     * Get comments for a post.
     * Supports parent_id query param to fetch replies for a specific comment.
     */
    public function index(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $parentId = $request->query('parent_id');
        $currentUserId = $request->query('user_id') ?? $request->query('current_user_id');

        $query = Comment::where('post_id', $postId);

        if ($parentId) {
            // Fetch replies for a specific parent comment
            $query->where('parent_id', $parentId);
        } else {
            // Fetch top-level comments only
            $query->whereNull('parent_id');
        }

        $comments = $query
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'replies.user:id,first_name,last_name,username,profile_photo_path'
            ])
            ->withCount('replies')
            ->orderByDesc('is_pinned')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add is_liked and reply_count for current user
        if ($currentUserId) {
            foreach ($comments as $comment) {
                $comment->is_liked = $comment->isLikedBy($currentUserId);
                $comment->reply_count = $comment->replies_count;
                foreach ($comment->replies as $reply) {
                    $reply->is_liked = $reply->isLikedBy($currentUserId);
                    $reply->reply_count = 0;
                }
            }
        } else {
            foreach ($comments as $comment) {
                $comment->reply_count = $comment->replies_count;
                foreach ($comment->replies as $reply) {
                    $reply->reply_count = 0;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    /**
     * Add a comment to a post.
     */
    public function store(Request $request, int $postId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
            'mention_ids' => 'nullable|array',
            'mention_ids.*' => 'exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        // Check if commenting is allowed on this post
        if (!$post->allow_comments) {
            return response()->json([
                'success' => false,
                'message' => 'Comments are disabled on this post',
            ], 403);
        }

        // If replying, verify parent comment belongs to same post
        if ($request->parent_id) {
            $parentComment = Comment::find($request->parent_id);
            if (!$parentComment || $parentComment->post_id !== $postId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid parent comment',
                ], 400);
            }
        }

        $comment = Comment::create([
            'post_id' => $postId,
            'user_id' => $request->user_id,
            'content' => $request->content,
            'parent_id' => $request->parent_id,
        ]);

        // Increment post comments count
        $post->incrementComments();

        $comment->load([
            'user:id,first_name,last_name,username,profile_photo_path',
            'replies',
        ]);

        $comment->reply_count = 0;
        $comment->is_liked = false;
        if ($request->mention_ids) {
            $comment->mentioned_user_ids = $request->mention_ids;
        }

        // Firebase: notify post author that post updated (new comment)
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $postId]);

        return response()->json([
            'success' => true,
            'message' => 'Comment added',
            'data' => $comment,
        ], 201);
    }

    /**
     * Update a comment.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
            'mention_ids' => 'nullable|array',
            'mention_ids.*' => 'exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comment->update([
            'content' => $request->content,
            'edited_at' => now(),
        ]);

        $comment = $comment->fresh([
            'user:id,first_name,last_name,username,profile_photo_path',
            'replies.user:id,first_name,last_name,username,profile_photo_path',
        ]);
        $comment->loadCount('replies');
        $comment->reply_count = $comment->replies_count;

        // Firebase: notify post author that post updated (comment edited)
        $post = Post::find($comment->post_id);
        if ($post) {
            $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $comment->post_id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Comment updated',
            'data' => $comment,
        ]);
    }

    /**
     * Delete a comment.
     */
    public function destroy(int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $post = $comment->post;
        $postId = $comment->post_id;

        // Count comment and its replies
        $repliesCount = $comment->replies()->count();
        $totalRemoved = 1 + $repliesCount;

        // Delete comment (will cascade to replies)
        $comment->delete();

        // Decrement post comments count
        for ($i = 0; $i < $totalRemoved; $i++) {
            $post->decrementComments();
        }

        // Firebase: notify post author that post updated (comment deleted)
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $postId]);

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted',
        ]);
    }

    /**
     * Get replies to a comment.
     */
    public function getReplies(Request $request, int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $currentUserId = $request->query('user_id') ?? $request->query('current_user_id');

        $replies = $comment->replies()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($replies as $reply) {
                $reply->is_liked = $reply->isLikedBy($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $replies->items(),
            'meta' => [
                'current_page' => $replies->currentPage(),
                'last_page' => $replies->lastPage(),
                'per_page' => $replies->perPage(),
                'total' => $replies->total(),
            ],
        ]);
    }

    /**
     * Like a comment.
     */
    public function like(Request $request, int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'user_id is required',
            ], 422);
        }

        $existing = CommentLike::where('comment_id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            // Idempotent: already liked, return current count
            return response()->json([
                'success' => true,
                'data' => [
                    'likes_count' => $comment->likes_count,
                    'is_liked' => true,
                ],
            ]);
        }

        CommentLike::create([
            'comment_id' => $id,
            'user_id' => $userId,
        ]);

        $comment->incrementLikes();

        // Firebase: notify post author that post updated (comment liked)
        $post = Post::find($comment->post_id);
        if ($post) {
            $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $comment->post_id]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => $comment->fresh()->likes_count,
                'is_liked' => true,
            ],
        ]);
    }

    /**
     * Unlike a comment.
     */
    public function unlike(Request $request, int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'user_id is required',
            ], 422);
        }

        $like = CommentLike::where('comment_id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$like) {
            // Idempotent: not liked, return current count
            return response()->json([
                'success' => true,
                'data' => [
                    'likes_count' => $comment->likes_count,
                    'is_liked' => false,
                ],
            ]);
        }

        $like->delete();
        $comment->decrementLikes();

        // Firebase: notify post author that post updated (comment unliked)
        $post = Post::find($comment->post_id);
        if ($post) {
            $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $comment->post_id]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => $comment->fresh()->likes_count,
                'is_liked' => false,
            ],
        ]);
    }

    /**
     * Pin a comment (post author only). Only one pinned comment per post.
     */
    public function pin(Request $request, int $postId, int $commentId): JsonResponse
    {
        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        // Check authorization - only post author can pin
        $userId = $request->input('user_id');
        if ($userId && (int) $userId !== (int) $post->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the post author can pin comments',
            ], 403);
        }

        $comment = Comment::where('id', $commentId)
            ->where('post_id', $postId)
            ->first();

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        // Unpin any existing pinned comment on this post
        Comment::where('post_id', $postId)
            ->where('is_pinned', true)
            ->update(['is_pinned' => false]);

        // Pin the requested comment
        $comment->update(['is_pinned' => true]);

        $comment = $comment->fresh([
            'user:id,first_name,last_name,username,profile_photo_path',
            'replies.user:id,first_name,last_name,username,profile_photo_path',
        ]);

        // Firebase: notify post author that post updated (comment pinned)
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $postId]);

        return response()->json([
            'success' => true,
            'message' => 'Comment pinned',
            'data' => $comment,
        ]);
    }

    /**
     * Unpin the pinned comment on a post.
     */
    public function unpin(int $postId): JsonResponse
    {
        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        Comment::where('post_id', $postId)
            ->where('is_pinned', true)
            ->update(['is_pinned' => false]);

        // Firebase: notify post author that post updated (comment unpinned)
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $postId]);

        return response()->json([
            'success' => true,
            'message' => 'Comment unpinned',
        ]);
    }

    /**
     * Report a comment.
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'user_id is required',
            ], 422);
        }

        // Check for duplicate report
        $existing = Report::where('reportable_type', Comment::class)
            ->where('reportable_id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Report already submitted',
            ]);
        }

        Report::create([
            'reportable_type' => Comment::class,
            'reportable_id' => $id,
            'user_id' => $userId,
            'reason' => $request->reason,
            'category' => $request->category,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted',
        ]);
    }
}
