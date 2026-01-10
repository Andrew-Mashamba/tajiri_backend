<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CommentController extends Controller
{
    /**
     * Get comments for a post.
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

        $comments = Comment::where('post_id', $postId)
            ->whereNull('parent_id')
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'replies.user:id,first_name,last_name,username,profile_photo_path'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

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

        $comment->load('user:id,first_name,last_name,username,profile_photo_path');

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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comment->update(['content' => $request->content]);

        return response()->json([
            'success' => true,
            'message' => 'Comment updated',
            'data' => $comment->fresh('user:id,first_name,last_name,username,profile_photo_path'),
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

        // Count comment and its replies
        $repliesCount = $comment->replies()->count();
        $totalRemoved = 1 + $repliesCount;

        // Delete comment (will cascade to replies)
        $comment->delete();

        // Decrement post comments count
        for ($i = 0; $i < $totalRemoved; $i++) {
            $post->decrementComments();
        }

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted',
        ]);
    }

    /**
     * Get replies to a comment.
     */
    public function getReplies(int $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $replies = $comment->replies()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $replies,
        ]);
    }
}
