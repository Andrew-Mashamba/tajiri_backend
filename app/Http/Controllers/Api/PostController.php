<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostMedia;
use App\Models\PostSave;
use App\Models\PostView;
use App\Models\Hashtag;
use App\Models\UserProfile;
use App\Models\Friend;
use App\Services\VideoProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    /**
     * Get posts for a user's wall.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $posts = Post::where('user_id', $userId)
            ->published()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Create a new post.
     */
    public function store(Request $request): JsonResponse
    {
        \Log::info('=== POST STORE START ===');
        \Log::info('Request data:', $request->except('media'));
        \Log::info('Has media files: ' . ($request->hasFile('media') ? 'yes' : 'no'));

        if ($request->hasFile('media')) {
            $files = $request->file('media');
            \Log::info('Media files count: ' . count($files));
            foreach ($files as $index => $file) {
                \Log::info("Media[$index]: " . $file->getClientOriginalName() . ', size: ' . $file->getSize() . ', mime: ' . $file->getMimeType());
            }
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'nullable|string|max:5000',
            'post_type' => 'in:text,photo,video,poll,shared',
            'privacy' => 'in:public,friends,private',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'region_id' => 'nullable|exists:regions,id',
            'tagged_users' => 'nullable|array',
            'tagged_users.*' => 'exists:user_profiles,id',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4,mov,avi,mp3,wav,pdf,doc,docx|max:51200',
            'is_draft' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            \Log::warning('Validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        \Log::info('Validation passed');

        try {
            DB::beginTransaction();
            \Log::info('Transaction started');

            // Create post
            $postData = [
                'user_id' => $request->user_id,
                'content' => $request->content,
                'post_type' => $request->post_type ?? 'text',
                'privacy' => $request->privacy ?? 'public',
                'location_name' => $request->location_name,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'region_id' => $request->region_id,
                'tagged_users' => $request->tagged_users,
                'is_draft' => $request->is_draft ?? false,
                'scheduled_at' => $request->scheduled_at,
            ];
            \Log::info('Creating post with data:', $postData);

            $post = Post::create($postData);
            \Log::info('Post created with id: ' . $post->id);

            // Handle media uploads
            $hasVideo = false;
            $videoDuration = 0;
            $videoService = new VideoProcessingService();

            if ($request->hasFile('media')) {
                $order = 0;
                foreach ($request->file('media') as $file) {
                    $mediaType = $this->getMediaType($file->getMimeType());
                    $path = $file->store('posts/' . $post->id, 'public');

                    $mediaData = [
                        'post_id' => $post->id,
                        'media_type' => $mediaType,
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'order' => $order++,
                    ];

                    // Get image dimensions
                    if ($mediaType === 'image') {
                        $imageInfo = getimagesize($file->getRealPath());
                        if ($imageInfo) {
                            $mediaData['width'] = $imageInfo[0];
                            $mediaData['height'] = $imageInfo[1];
                        }
                    }

                    // Process video with VideoProcessingService
                    if ($mediaType === 'video') {
                        $hasVideo = true;
                        $fullPath = Storage::disk('public')->path($path);
                        $videoMeta = $videoService->getVideoMetadata($fullPath);

                        if ($videoMeta['duration']) {
                            $mediaData['duration'] = (int) round($videoMeta['duration']);
                            $videoDuration = max($videoDuration, $mediaData['duration']);
                        }

                        $mediaData['width'] = $videoMeta['width'];
                        $mediaData['height'] = $videoMeta['height'];

                        // Generate thumbnail
                        $thumbnail = $videoService->generateThumbnail($fullPath, 'public');
                        if ($thumbnail) {
                            $mediaData['thumbnail_path'] = $thumbnail;
                        }
                    }

                    PostMedia::create($mediaData);
                }

                // Update post type based on media
                if ($post->post_type === 'text') {
                    $post->update(['post_type' => $hasVideo ? 'video' : 'photo']);
                }

                // Mark as short video if <= 60 seconds
                if ($hasVideo && $videoDuration > 0 && $videoDuration <= 60) {
                    $post->update(['is_short_video' => true]);
                }
            }

            // Extract and sync hashtags
            $post->extractAndSyncHashtags();

            // Increment user's posts count (only if not draft)
            if (!$post->is_draft) {
                UserProfile::find($request->user_id)->incrementPosts();
            }

            DB::commit();

            \Log::info('Post created successfully, id: ' . $post->id);

            // Load relationships
            $post->load(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags']);

            \Log::info('=== POST STORE END (SUCCESS) ===');

            return response()->json([
                'success' => true,
                'message' => 'Post created successfully',
                'data' => $post,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Post creation failed: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            \Log::info('=== POST STORE END (ERROR) ===');

            return response()->json([
                'success' => false,
                'message' => 'Failed to create post: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single post.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $post = Post::with(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags'])
            ->withCount(['comments', 'likes'])
            ->find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $post->is_liked = $post->isLikedBy($currentUserId);
            $post->is_saved = $post->isSavedBy($currentUserId);
            $post->user_reaction = $post->getReactionBy($currentUserId);
        }

        return response()->json([
            'success' => true,
            'data' => $post,
        ]);
    }

    /**
     * Update a post.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'nullable|string|max:5000',
            'privacy' => 'in:public,friends,private',
            'location_name' => 'nullable|string|max:255',
            'is_pinned' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $post->update($request->only(['content', 'privacy', 'location_name', 'is_pinned']));

        // Re-extract hashtags if content changed
        if ($request->has('content')) {
            $post->extractAndSyncHashtags();
        }

        return response()->json([
            'success' => true,
            'message' => 'Post updated successfully',
            'data' => $post->fresh(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags']),
        ]);
    }

    /**
     * Delete a post.
     */
    public function destroy(int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Delete media files
            foreach ($post->media as $media) {
                Storage::disk('public')->delete($media->file_path);
                if ($media->thumbnail_path) {
                    Storage::disk('public')->delete($media->thumbnail_path);
                }
            }

            // Decrement hashtag counts
            foreach ($post->hashtags as $hashtag) {
                $hashtag->decrement('posts_count');
            }

            // Decrement user's posts count
            $post->user->decrementPosts();

            // Delete post (soft delete)
            $post->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Post deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete post: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==================== FEED ENDPOINTS ====================

    /**
     * Get "For You" feed - personalized algorithmic feed
     * Inspired by TikTok/Instagram's discovery algorithm
     */
    public function forYouFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $offset = ($page - 1) * $perPage;

        $posts = Post::forYouFeed($userId ?? 0, $perPage, $offset);

        // Mark engagement status for current user
        if ($userId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($userId);
                $post->is_saved = $post->isSavedBy($userId);
                $post->user_reaction = $post->getReactionBy($userId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'has_more' => $posts->count() === $perPage,
            ],
        ]);
    }

    /**
     * Get "Following" feed - posts from friends (chronological)
     * Inspired by Twitter's Following tab
     */
    public function followingFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $offset = ($page - 1) * $perPage;

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        // Get friend IDs
        $friendIds = Friend::where('user_id', $userId)
            ->where('status', 'accepted')
            ->pluck('friend_id')
            ->toArray();

        // Include own posts
        $friendIds[] = $userId;

        $posts = Post::followingFeed($userId, $friendIds, $perPage, $offset);

        foreach ($posts as $post) {
            $post->is_liked = $post->isLikedBy($userId);
            $post->is_saved = $post->isSavedBy($userId);
            $post->user_reaction = $post->getReactionBy($userId);
        }

        return response()->json([
            'success' => true,
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'has_more' => $posts->count() === $perPage,
            ],
        ]);
    }

    /**
     * Get short videos feed (TikTok/Reels/Shorts style)
     */
    public function shortsFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 10); // Fewer items for vertical scroll
        $offset = ($page - 1) * $perPage;

        $posts = Post::shortVideosFeed($userId ?? 0, $perPage, $offset);

        if ($userId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($userId);
                $post->is_saved = $post->isSavedBy($userId);
                $post->user_reaction = $post->getReactionBy($userId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'has_more' => $posts->count() === $perPage,
            ],
        ]);
    }

    /**
     * Get trending feed
     */
    public function trendingFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $offset = ($page - 1) * $perPage;

        $posts = Post::trendingFeed($perPage, $offset);

        if ($userId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($userId);
                $post->is_saved = $post->isSavedBy($userId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'has_more' => $posts->count() === $perPage,
            ],
        ]);
    }

    /**
     * Get discover feed (public posts from non-friends)
     */
    public function discoverFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $friendIds = [];
        if ($userId) {
            $friendIds = Friend::where('user_id', $userId)
                ->where('status', 'accepted')
                ->pluck('friend_id')
                ->toArray();
            $friendIds[] = $userId;
        }

        $posts = Post::public()
            ->published()
            ->when($userId, function ($query) use ($friendIds) {
                $query->whereNotIn('user_id', $friendIds);
            })
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount('comments')
            ->orderByRaw('(trending_score * 0.3 + engagement_score * 0.7) DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($userId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($userId);
                $post->is_saved = $post->isSavedBy($userId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    // ==================== ENGAGEMENT ENDPOINTS ====================

    /**
     * Record a view on a post (for engagement tracking)
     */
    public function recordView(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:user_profiles,id',
            'session_id' => 'nullable|string|max:64',
            'watch_time_seconds' => 'nullable|integer|min:0',
            'watch_percentage' => 'nullable|numeric|between:0,100',
            'source' => 'nullable|in:feed,profile,discover,search,share,shorts',
            'device_type' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        PostView::recordView(
            $id,
            $request->user_id,
            $request->session_id,
            $request->watch_time_seconds ?? 0,
            $request->watch_percentage ?? 0,
            $request->source ?? 'feed',
            $request->device_type
        );

        return response()->json([
            'success' => true,
            'message' => 'View recorded',
        ]);
    }

    /**
     * Like a post.
     */
    public function like(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'reaction_type' => 'in:like,love,haha,wow,sad,angry',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $existingLike = PostLike::where('post_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingLike) {
            if ($existingLike->reaction_type !== ($request->reaction_type ?? 'like')) {
                $existingLike->update(['reaction_type' => $request->reaction_type ?? 'like']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Reaction updated',
                'data' => [
                    'likes_count' => $post->likes_count,
                    'reaction_type' => $existingLike->reaction_type,
                ],
            ]);
        }

        PostLike::create([
            'post_id' => $id,
            'user_id' => $request->user_id,
            'reaction_type' => $request->reaction_type ?? 'like',
        ]);

        $post->incrementLikes();

        return response()->json([
            'success' => true,
            'message' => 'Post liked',
            'data' => [
                'likes_count' => $post->fresh()->likes_count,
                'reaction_type' => $request->reaction_type ?? 'like',
            ],
        ]);
    }

    /**
     * Unlike a post.
     */
    public function unlike(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $like = PostLike::where('post_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$like) {
            return response()->json([
                'success' => false,
                'message' => 'Post not liked',
            ], 400);
        }

        $like->delete();
        $post->decrementLikes();

        return response()->json([
            'success' => true,
            'message' => 'Post unliked',
            'data' => ['likes_count' => $post->fresh()->likes_count],
        ]);
    }

    /**
     * Save/bookmark a post.
     */
    public function savePost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'collection_id' => 'nullable|exists:save_collections,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        PostSave::savePost($id, $request->user_id, $request->collection_id);

        return response()->json([
            'success' => true,
            'message' => 'Post saved',
            'data' => ['saves_count' => $post->fresh()->saves_count],
        ]);
    }

    /**
     * Unsave a post.
     */
    public function unsavePost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        PostSave::unsavePost($id, $request->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Post unsaved',
            'data' => ['saves_count' => $post->fresh()->saves_count],
        ]);
    }

    /**
     * Get saved posts for a user.
     */
    public function getSavedPosts(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $savedPostIds = PostSave::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->pluck('post_id');

        $posts = Post::whereIn('id', $savedPostIds)
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount('comments')
            ->paginate($perPage, ['*'], 'page', $page);

        foreach ($posts as $post) {
            $post->is_liked = $post->isLikedBy($userId);
            $post->is_saved = true;
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get users who liked a post.
     */
    public function getLikes(int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $likes = PostLike::where('post_id', $id)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $likes,
        ]);
    }

    /**
     * Get posts for user's wall.
     */
    public function getUserWall(Request $request, int $userId): JsonResponse
    {
        \Log::info("=== GET USER WALL ===");
        \Log::info("userId: $userId");

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $currentUserId = $request->query('current_user_id');

        \Log::info("page: $page, perPage: $perPage, currentUserId: $currentUserId");

        // Check total posts for this user (without published filter)
        $totalPosts = Post::where('user_id', $userId)->count();
        \Log::info("Total posts for user (without filter): $totalPosts");

        // Check drafts
        $draftCount = Post::where('user_id', $userId)->where('is_draft', true)->count();
        \Log::info("Draft posts: $draftCount");

        // Check scheduled
        $scheduledCount = Post::where('user_id', $userId)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', \Carbon\Carbon::now())
            ->count();
        \Log::info("Scheduled posts: $scheduledCount");

        $posts = Post::where('user_id', $userId)
            ->published()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        \Log::info("Published posts found: " . $posts->total());

        if ($currentUserId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($currentUserId);
                $post->is_saved = $post->isSavedBy($currentUserId);
            }
        }

        \Log::info("=== GET USER WALL END ===");

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Share a post.
     */
    public function share(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'nullable|string|max:5000',
            'privacy' => 'in:public,friends,private',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $originalPost = Post::find($id);

        if (!$originalPost) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            $sharedPost = Post::create([
                'user_id' => $request->user_id,
                'content' => $request->content,
                'post_type' => 'shared',
                'privacy' => $request->privacy ?? 'public',
                'original_post_id' => $id,
            ]);

            $originalPost->incrementShares();
            UserProfile::find($request->user_id)->incrementPosts();

            DB::commit();

            $sharedPost->load(['user:id,first_name,last_name,username,profile_photo_path', 'originalPost.user', 'originalPost.media']);

            return response()->json([
                'success' => true,
                'message' => 'Post shared successfully',
                'data' => $sharedPost,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to share post: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==================== HASHTAG ENDPOINTS ====================

    /**
     * Search posts by hashtag (using normalized hashtag table).
     */
    public function searchByHashtag(Request $request, string $hashtag): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $currentUserId = $request->query('current_user_id');

        $posts = Post::withHashtag($hashtag)
            ->public()
            ->published()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->orderByDesc('engagement_score')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($currentUserId);
                $post->is_saved = $post->isSavedBy($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get trending hashtags.
     */
    public function trendingHashtags(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 20);

        $hashtags = Hashtag::trending($limit);

        return response()->json([
            'success' => true,
            'data' => $hashtags,
        ]);
    }

    /**
     * Search hashtags for autocomplete.
     */
    public function searchHashtags(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $limit = $request->query('limit', 10);

        if (strlen($query) < 1) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $hashtags = Hashtag::search($query, $limit);

        return response()->json([
            'success' => true,
            'data' => $hashtags,
        ]);
    }

    /**
     * Search posts by mention.
     */
    public function searchByMention(Request $request, string $username): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $currentUserId = $request->query('current_user_id');

        $posts = Post::where('content', 'LIKE', '%@' . $username . '%')
            ->public()
            ->published()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Determine media type from mime type.
     */
    private function getMediaType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        return 'document';
    }

    /**
     * Get video duration using FFmpeg (if available).
     */
    private function getVideoDuration(string $path): ?int
    {
        try {
            // Try using FFprobe if available
            $output = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($path) . " 2>&1");

            if ($output && is_numeric(trim($output))) {
                return (int) round(floatval(trim($output)));
            }
        } catch (\Exception $e) {
            // FFmpeg not available
        }

        return null;
    }
}
