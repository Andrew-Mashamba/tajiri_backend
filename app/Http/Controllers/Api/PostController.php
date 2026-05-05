<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostMedia;
use App\Models\PostSave;
use App\Models\PostView;
use App\Models\Report;
use App\Models\Hashtag;
use App\Models\UserProfile;
use App\Models\Friend;
use App\Jobs\ComposeReplyVideo;
use App\Services\VideoProcessingService;
use App\Services\AudioProcessingService;
use App\Services\ImageProcessingService;
use App\Services\Firebase\FirebaseLiveUpdateService;
use App\Support\FormCast;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PostController extends Controller
{
    protected FirebaseLiveUpdateService $firebaseLiveUpdate;

    public function __construct(FirebaseLiveUpdateService $firebaseLiveUpdate)
    {
        $this->firebaseLiveUpdate = $firebaseLiveUpdate;
    }
    /**
     * Get posts for a user's wall.
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('=== GET POSTS INDEX START ===');
        \Log::info('Request URL: ' . $request->fullUrl());
        \Log::info('Request method: ' . $request->method());
        \Log::info('Request IP: ' . $request->ip());
        \Log::info('Request headers: ', $request->headers->all());
        \Log::info('Query parameters: ', $request->query());

        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        \Log::info("Parsed params - userId: $userId, page: $page, perPage: $perPage");

        if (!$userId) {
            \Log::warning('GET POSTS INDEX: User ID is required but not provided');
            \Log::info('=== GET POSTS INDEX END (ERROR: No User ID) ===');
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        try {
            \Log::info("Querying global feed for requesting user_id: $userId");

            // Check if a specific profile's posts are requested
            $profileUserId = $request->query('profile_user_id');

            if ($profileUserId) {
                // Return only that profile's posts
                \Log::info("Fetching posts for profile user_id: $profileUserId");
                $query = Post::where('user_id', $profileUserId);
            } else {
                // Return global timeline (all public posts)
                \Log::info("Fetching global timeline");
                $query = Post::where('privacy', 'public');
            }

            $posts = $query->published()
                ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
                ->withCount(['comments', 'likes'])
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            \Log::info('Query executed successfully');
            \Log::info("Results - total: {$posts->total()}, current_page: {$posts->currentPage()}, last_page: {$posts->lastPage()}");
            \Log::info('Posts IDs returned: ' . $posts->pluck('id')->implode(', '));

            $response = [
                'success' => true,
                'data' => $posts->items(),
                'meta' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ];

            \Log::info('=== GET POSTS INDEX END (SUCCESS) ===');

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('GET POSTS INDEX ERROR: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            \Log::info('=== GET POSTS INDEX END (EXCEPTION) ===');

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch posts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new post.
     */
    public function store(Request $request): JsonResponse
    {
        \Log::info('=== POST STORE START ===');
        \Log::info('Request data:', $request->except('media', 'audio'));
        \Log::info('Has media files: ' . ($request->hasFile('media') ? 'yes' : 'no'));
        \Log::info('Has audio file: ' . ($request->hasFile('audio') ? 'yes' : 'no'));
        \Log::info('All files:', array_keys($request->allFiles()));

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
            'post_type' => 'in:text,photo,video,short_video,audio,audio_text,image_text,poll,shared',
            'privacy' => 'in:public,friends,private',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'region_id' => 'nullable|exists:regions,id',
            'tagged_users' => 'nullable|array',
            'tagged_users.*' => 'exists:user_profiles,id',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4,mov,avi,webm,mp3,wav,m4a,aac,ogg,pdf,doc,docx|max:102400',
            'is_draft' => 'nullable|boolean',
            'allow_comments' => FormCast::allowCommentsRule(),
            'scheduled_at' => 'nullable|date|after:now',
            // New fields for enhanced post types
            'background_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'audio' => 'nullable|file|mimes:mp3,wav,m4a,aac,ogg|max:20480',
            'cover_image' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'music_track_id' => 'nullable|exists:music_tracks,id',
            'music_start_time' => 'nullable|integer|min:0',
            'original_audio_volume' => 'nullable|numeric|between:0,1',
            'music_volume' => 'nullable|numeric|between:0,1',
            'video_speed' => 'nullable|numeric|between:0.5,2',
            'text_overlays' => 'nullable|json',
            'video_filter' => 'nullable|string|max:50',
            'reply_to_post_id' => 'nullable|integer|exists:posts,id',
            'stitch_from_post_id' => 'nullable|integer|exists:posts,id',
            'reply_layout' => 'nullable|string|in:side_by_side,top_bottom,pip',
            'stitch_trim_start_ms' => 'nullable|integer|min:0',
            'stitch_trim_end_ms' => 'nullable|integer|min:0',
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
                'allow_comments' => FormCast::toBoolean($request->allow_comments, true),
                'scheduled_at' => $request->scheduled_at,
                // New fields for enhanced post types
                'background_color' => $request->background_color,
                'music_track_id' => $request->music_track_id,
                'music_start_time' => $request->music_start_time,
                'original_audio_volume' => $request->original_audio_volume ?? 1.0,
                'music_volume' => $request->music_volume ?? 1.0,
                'video_speed' => $request->video_speed ?? 1.0,
                'text_overlays' => $request->text_overlays ? json_decode($request->text_overlays, true) : null,
                'video_filter' => $request->video_filter,
                'reply_to_post_id' => $request->reply_to_post_id,
                'stitch_from_post_id' => $request->stitch_from_post_id,
                'reply_layout' => $request->reply_layout,
                'stitch_trim_start_ms' => $request->stitch_trim_start_ms,
                'stitch_trim_end_ms' => $request->stitch_trim_end_ms,
            ];
            \Log::info('Creating post with data:', $postData);

            $post = Post::create($postData);
            \Log::info('Post created with id: ' . $post->id);

            // Initialize services
            $videoService = new VideoProcessingService();
            $audioService = new AudioProcessingService();

            // Handle standalone audio upload (for audio/audio_text posts)
            if ($request->hasFile('audio')) {
                \Log::info('Processing standalone audio file');
                $audioFile = $request->file('audio');
                $audioData = $audioService->processAudio($audioFile);

                $post->update([
                    'audio_path' => $audioData['path'],
                    'audio_duration' => $audioData['duration'] ? (int) ceil($audioData['duration']) : null,
                    'audio_waveform' => $audioData['waveform'],
                ]);
                \Log::info('Audio processed: duration=' . ($audioData['duration'] ?? 'null'));
            }

            // Handle cover image for audio posts
            if ($request->hasFile('cover_image')) {
                \Log::info('Processing cover image');
                $coverImage = $request->file('cover_image');
                $coverPath = $coverImage->store('posts/' . $post->id . '/covers', 'public');
                $post->update(['cover_image_path' => $coverPath]);
                \Log::info('Cover image saved: ' . $coverPath);
            }

            // Handle media uploads
            $hasVideo = false;
            $videoDuration = 0;

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

                    // Process audio sent via media[] field
                    if ($mediaType === 'audio' && !$post->audio_path) {
                        $audioData = $audioService->processAudio($file);
                        $post->update([
                            'audio_path' => $audioData['path'],
                            'audio_duration' => $audioData['duration'] ? (int) ceil($audioData['duration']) : null,
                            'audio_waveform' => $audioData['waveform'],
                            'post_type' => 'audio',
                        ]);
                        $mediaData['duration'] = $audioData['duration'] ? (int) ceil($audioData['duration']) : null;
                    }

                    PostMedia::create($mediaData);

                    // Process grid thumbnail + dominant color extraction
                    $createdMedia = PostMedia::where("post_id", $post->id)->where("order", $mediaData["order"])->first();
                    if ($createdMedia && in_array($mediaType, ["image", "video"])) {
                        try {
                            app(ImageProcessingService::class)->processForGrid($createdMedia);
                        } catch (\Throwable $e) {
                            \Log::warning("Grid thumbnail generation failed: " . $e->getMessage());
                        }
                    }
                }

                // Update post type based on media (if not explicitly set)
                if ($post->post_type === 'text') {
                    $post->update(['post_type' => $hasVideo ? 'video' : 'photo']);
                }

                // Handle short video detection
                if ($hasVideo && $videoDuration > 0 && $videoDuration <= 60) {
                    // If explicitly marked as short_video or detected as short
                    if ($post->post_type === 'video' || $post->post_type === 'short_video') {
                        $post->update([
                            'is_short_video' => true,
                            'post_type' => 'short_video',
                        ]);
                    }
                }
            }

            // Handle image_text type detection
            if ($post->post_type === 'photo' && !empty($post->content)) {
                $post->update(['post_type' => 'image_text']);
            }

            // Handle audio_text type detection
            if ($post->post_type === 'audio' && !empty($post->content)) {
                $post->update(['post_type' => 'audio_text']);
            }

            // Extract and sync hashtags
            $post->extractAndSyncHashtags();

            // Increment user's posts count (only if not draft)
            if (!$post->is_draft) {
                UserProfile::find($request->user_id)->incrementPosts();
            }

            DB::commit();


            // Dispatch video composition job for reply/stitch posts
            if ($post->reply_to_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'reply')->onQueue('video-compose');
            } elseif ($post->stitch_from_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'stitch')->onQueue('video-compose');
            }
            \Log::info('Post created successfully, id: ' . $post->id);

            // Firebase: notify author and friends that feed updated
            if (!$post->is_draft) {
                $this->firebaseLiveUpdate->notifyUserAndFriends((int) $request->user_id, 'feed_updated');
            }

            // Load relationships (include musicTrack for posts with music)
            $post->load(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags', 'musicTrack', 'replyToPost.user:id,first_name,last_name,username,profile_photo_path', 'replyToPost.media', 'stitchFromPost.user:id,first_name,last_name,username,profile_photo_path', 'stitchFromPost.media']);

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
        $post = Post::with(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags', 'musicTrack'])
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
            'allow_comments' => FormCast::allowCommentsRule(),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['content', 'privacy', 'location_name', 'is_pinned']);
        if ($request->has('allow_comments')) {
            $updateData['allow_comments'] = FormCast::toBoolean($request->allow_comments, true);
        }
        $post->update($updateData);

        // Re-extract hashtags if content changed
        if ($request->has('content')) {
            $post->extractAndSyncHashtags();
        }

        // Firebase: notify author that post updated
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $post->id]);

        return response()->json([
            'success' => true,
            'message' => 'Post updated successfully',
            'data' => $post->fresh(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'hashtags']),
        ]);
    }

    /**
     * Delete a post.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        // Only the post author can delete
        $userId = $request->input('user_id') ?? $request->query('user_id');
        if ($userId && (int) $userId !== (int) $post->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this post',
            ], 403);
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

            $postAuthorId = (int) $post->user_id;
            $postId = $post->id;

            // Delete post (soft delete)
            $post->delete();

            DB::commit();


            // Dispatch video composition job for reply/stitch posts
            if ($post->reply_to_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'reply')->onQueue('video-compose');
            } elseif ($post->stitch_from_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'stitch')->onQueue('video-compose');
            }
            // Firebase: notify author with post_updated and friends with feed_updated
            $this->firebaseLiveUpdate->notifyUser($postAuthorId, 'post_updated', ['post_id' => $postId]);
            $this->firebaseLiveUpdate->notifyUserAndFriends($postAuthorId, 'feed_updated');

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
            $this->enrichPostUsers($posts, (int) $userId);
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
        $this->enrichPostUsers($posts, (int) $userId);

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
            $this->enrichPostUsers($posts, (int) $userId);
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
     * Get audio posts feed (voice notes, podcasts)
     */
    public function audioFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $offset = ($page - 1) * $perPage;

        $posts = Post::query()
            ->public()
            ->published()
            ->audioPosts()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media', 'musicTrack'])
            ->orderByRaw('(trending_score * 0.3 + engagement_score * 0.7) DESC')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        if ($userId) {
            foreach ($posts as $post) {
                $post->is_liked = $post->isLikedBy($userId);
                $post->is_saved = $post->isSavedBy($userId);
                $post->user_reaction = $post->getReactionBy($userId);
            }
            $this->enrichPostUsers($posts, (int) $userId);
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

        // Firebase: notify post author that post updated
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $id]);

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
            // Idempotent: unliking when not liked is a no-op
            return response()->json([
                'success' => true,
                'message' => 'Post not liked',
                'data' => ['likes_count' => $post->likes_count],
            ]);
        }

        $like->delete();
        $post->decrementLikes();

        // Firebase: notify post author that post updated
        $this->firebaseLiveUpdate->notifyUser((int) $post->user_id, 'post_updated', ['post_id' => $id]);

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


            // Dispatch video composition job for reply/stitch posts
            if ($post->reply_to_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'reply')->onQueue('video-compose');
            } elseif ($post->stitch_from_post_id) {
                ComposeReplyVideo::dispatch($post->id, 'stitch')->onQueue('video-compose');
            }
            // Firebase: notify sharer's friends with feed_updated, and original author with post_updated
            $this->firebaseLiveUpdate->notifyUserAndFriends((int) $request->user_id, 'feed_updated');
            $this->firebaseLiveUpdate->notifyUser((int) $originalPost->user_id, 'post_updated', ['post_id' => $originalPost->id]);

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

    // ==================== INSTAGRAM-STYLE PROFILE GRID ENDPOINTS ====================

    /**
     * Archive a post (hide from grid, keep privately).
     * POST /api/posts/{id}/archive
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID is required'], 400);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        if ($post->user_id != $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $post->update(['status' => Post::STATUS_ARCHIVED]);

        // Fire live update
        try {
            $this->firebaseLiveUpdate->notifyProfileUpdate($post->user_id, 'post_archived');
        } catch (\Throwable $e) {
            \Log::warning('Firebase notification failed for archive: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Post archived successfully',
        ]);
    }

    /**
     * Unarchive a post (restore to grid at original position).
     * POST /api/posts/{id}/unarchive
     */
    public function unarchive(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID is required'], 400);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        if ($post->user_id != $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($post->status !== Post::STATUS_ARCHIVED) {
            return response()->json(['success' => false, 'message' => 'Post is not archived'], 422);
        }

        $post->update(['status' => Post::STATUS_PUBLISHED]);

        try {
            $this->firebaseLiveUpdate->notifyProfileUpdate($post->user_id, 'post_unarchived');
        } catch (\Throwable $e) {
            \Log::warning('Firebase notification failed for unarchive: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Post restored successfully',
        ]);
    }

    /**
     * Get archived posts for the current user.
     * GET /api/posts/archived
     */
    public function archivedPosts(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID is required'], 400);
        }

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 24);

        $posts = Post::where('user_id', $userId)
            ->where('status', Post::STATUS_ARCHIVED)
            ->with(['media', 'user:id,first_name,last_name,username,profile_photo_path'])
            ->orderByDesc('created_at')
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
     * Pin a post to profile grid (max 3 pinned posts).
     * POST /api/posts/{id}/pin
     */
    public function pin(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID is required'], 400);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        if ($post->user_id != $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($post->is_pinned) {
            return response()->json(['success' => true, 'message' => 'Post is already pinned']);
        }

        // Check max 3 pinned posts
        $pinnedCount = Post::where('user_id', $userId)
            ->where('is_pinned', true)
            ->where('status', Post::STATUS_PUBLISHED)
            ->count();

        if ($pinnedCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum 3 posts can be pinned. Unpin one first.',
            ], 422);
        }

        $post->update(['is_pinned' => true]);

        try {
            $this->firebaseLiveUpdate->notifyProfileUpdate($post->user_id, 'post_pinned');
        } catch (\Throwable $e) {
            \Log::warning('Firebase notification failed for pin: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Post pinned to profile',
        ]);
    }

    /**
     * Unpin a post from profile grid.
     * POST /api/posts/{id}/unpin
     */
    public function unpin(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID is required'], 400);
        }

        $post = Post::find($id);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        if ($post->user_id != $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $post->update(['is_pinned' => false]);

        try {
            $this->firebaseLiveUpdate->notifyProfileUpdate($post->user_id, 'post_unpinned');
        } catch (\Throwable $e) {
            \Log::warning('Firebase notification failed for unpin: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Post unpinned',
        ]);
    }

    /**
     * Report a post.
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
            'reason' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (int) $request->input('user_id');
        $reason = $request->input('reason');

        // reports.reason is non-nullable in the database schema, so ensure we always write a value.
        if ($reason === null || $reason === '') {
            $reason = 'No reason provided';
        }

        // Check for duplicate report
        $existing = Report::where('reportable_type', Post::class)
            ->where('reportable_id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Report already submitted',
            ]);
        }

        try {
            Report::create([
                'reportable_type' => Post::class,
                'reportable_id' => $id,
                'user_id' => $userId,
                'reason' => $reason,
                'category' => $request->input('category'),
            ]);
        } catch (\Throwable $e) {
            // Unique constraint on (reportable_type, reportable_id, user_id) protects duplicates.
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report already submitted',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit report',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Report submitted',
        ]);
    }

    /**
     * Get grid thumbnail URL for a media item.
     * Generates on-demand if not yet processed.
     * GET /api/posts/media/{mediaId}/grid-thumbnail
     */
    public function gridThumbnail(int $mediaId): JsonResponse
    {
        $media = PostMedia::find($mediaId);
        if (!$media) {
            return response()->json(['success' => false, 'message' => 'Media not found'], 404);
        }

        // Generate on-demand if missing
        if (!$media->grid_thumbnail_path || !$media->dominant_color) {
            $processor = app(\App\Services\ImageProcessingService::class);
            $processor->processForGrid($media);
            $media->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'grid_thumbnail_url' => $media->grid_thumbnail_url,
                'dominant_color' => $media->dominant_color,
            ],
        ]);
    }

    /**
     * Enrich posts with is_following and mutual follower data on the user object.
     * Call after loading posts and before returning JSON.
     */
    protected function enrichPostUsers(iterable $posts, ?int $currentUserId): void
    {
        if (!$currentUserId) return;

        // Collect unique author IDs (exclude self)
        $authorIds = collect($posts)
            ->pluck("user_id")
            ->unique()
            ->filter(fn($id) => $id !== $currentUserId)
            ->values()
            ->toArray();

        if (empty($authorIds)) return;

        // Batch check: which authors does the current user follow?
        $followingIds = \App\Models\UserFollow::where("follower_id", $currentUserId)
            ->whereIn("following_id", $authorIds)
            ->pluck("following_id")
            ->toArray();

        // Get who the current user follows
        $myFollowingIds = \App\Models\UserFollow::where("follower_id", $currentUserId)
            ->pluck("following_id")
            ->toArray();

        // For each author, find mutual followers (people current user follows who also follow this author)
        $mutualData = [];
        if (!empty($myFollowingIds)) {
            foreach ($authorIds as $authorId) {
                // People who follow this author AND are followed by current user
                $mutuals = \App\Models\UserFollow::where("following_id", $authorId)
                    ->whereIn("follower_id", $myFollowingIds)
                    ->limit(3)
                    ->pluck("follower_id")
                    ->toArray();

                if (!empty($mutuals)) {
                    $names = \App\Models\UserProfile::whereIn("id", array_slice($mutuals, 0, 2))
                        ->pluck("username")
                        ->toArray();
                    $totalCount = \App\Models\UserFollow::where("following_id", $authorId)
                        ->whereIn("follower_id", $myFollowingIds)
                        ->count();
                    $mutualData[$authorId] = ["names" => $names, "count" => $totalCount];
                }
            }
        }

        // Attach to each post user
        foreach ($posts as $post) {
            if ($post->user) {
                $post->user->is_following = in_array($post->user_id, $followingIds);
                $mutual = $mutualData[$post->user_id] ?? null;
                $post->user->mutual_followers_count = $mutual ? $mutual["count"] : 0;
                $post->user->mutual_follower_names = $mutual ? $mutual["names"] : [];
            }
        }
    }

}
