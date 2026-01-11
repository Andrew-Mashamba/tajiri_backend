<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostDraft;
use App\Models\Post;
use App\Services\AudioProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostDraftController extends Controller
{
    protected AudioProcessingService $audioService;

    public function __construct(AudioProcessingService $audioService)
    {
        $this->audioService = $audioService;
    }

    /**
     * Get all drafts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $query = PostDraft::forUser((int) $userId)
            ->with('musicTrack')
            ->recent();

        // Filter by type if specified
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        // Filter scheduled only
        if ($request->boolean('scheduled_only')) {
            $query->scheduled();
        }

        $drafts = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $drafts->items(),
            'meta' => [
                'current_page' => $drafts->currentPage(),
                'last_page' => $drafts->lastPage(),
                'per_page' => $drafts->perPage(),
                'total' => $drafts->total(),
            ],
        ]);
    }

    /**
     * Get a specific draft.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $draft = PostDraft::forUser((int) $userId)
            ->with('musicTrack')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $draft,
        ]);
    }

    /**
     * Create or update a draft (auto-save).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:user_profiles,id',
            'draft_id' => 'nullable|integer|exists:post_drafts,id',
            'post_type' => 'required|in:text,photo,video,short_video,audio',
            'content' => 'nullable|string|max:10000',
            'background_color' => 'nullable|string|max:7',
            'privacy' => 'nullable|in:public,friends,private',
            'location_name' => 'nullable|string|max:255',
            'location_lat' => 'nullable|numeric|between:-90,90',
            'location_lng' => 'nullable|numeric|between:-180,180',
            'tagged_users' => 'nullable|array',
            'tagged_users.*' => 'integer|exists:users,id',
            'scheduled_at' => 'nullable|date|after:now',
            'title' => 'nullable|string|max:255',

            // Media
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpeg,png,gif,webp,mp4,mov,avi,webm|max:102400',

            // Audio
            'audio' => 'nullable|file|mimes:mp3,wav,m4a,aac,ogg|max:20480',
            'cover_image' => 'nullable|file|mimes:jpeg,png,gif,webp|max:10240',

            // Video settings
            'music_track_id' => 'nullable|integer|exists:music_tracks,id',
            'music_start_time' => 'nullable|integer|min:0',
            'original_audio_volume' => 'nullable|numeric|between:0,1',
            'music_volume' => 'nullable|numeric|between:0,1',
            'video_speed' => 'nullable|numeric|between:0.25,4',
            'text_overlays' => 'nullable|json',
            'video_filter' => 'nullable|string|max:50',
        ]);

        $userId = $validated['user_id'];

        // Check if updating existing draft
        if (!empty($validated['draft_id'])) {
            $draft = PostDraft::forUser($userId)->findOrFail($validated['draft_id']);
        } else {
            $draft = new PostDraft();
            $draft->user_id = $userId;
        }

        // Update basic fields
        $draft->post_type = $validated['post_type'];
        $draft->content = $validated['content'] ?? $draft->content;
        $draft->background_color = $validated['background_color'] ?? $draft->background_color;
        $draft->privacy = $validated['privacy'] ?? $draft->privacy ?? 'public';
        $draft->location_name = $validated['location_name'] ?? $draft->location_name;
        $draft->location_lat = $validated['location_lat'] ?? $draft->location_lat;
        $draft->location_lng = $validated['location_lng'] ?? $draft->location_lng;
        $draft->tagged_users = $validated['tagged_users'] ?? $draft->tagged_users;
        $draft->scheduled_at = $validated['scheduled_at'] ?? $draft->scheduled_at;
        $draft->title = $validated['title'] ?? $draft->title;

        // Video settings
        $draft->music_track_id = $validated['music_track_id'] ?? $draft->music_track_id;
        $draft->music_start_time = $validated['music_start_time'] ?? $draft->music_start_time;
        $draft->original_audio_volume = $validated['original_audio_volume'] ?? $draft->original_audio_volume;
        $draft->music_volume = $validated['music_volume'] ?? $draft->music_volume;
        $draft->video_speed = $validated['video_speed'] ?? $draft->video_speed;
        $draft->video_filter = $validated['video_filter'] ?? $draft->video_filter;

        if (isset($validated['text_overlays'])) {
            $draft->text_overlays = json_decode($validated['text_overlays'], true);
        }

        // Handle media files
        if ($request->hasFile('media')) {
            $mediaFiles = [];
            $mediaMetadata = [];

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store('drafts/' . $userId . '/media', 'public');

                $mediaFile = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];

                // Generate thumbnail for images/videos
                if (Str::startsWith($file->getMimeType(), 'image/')) {
                    $mediaFile['type'] = 'image';
                    // Get image dimensions
                    $imageInfo = getimagesize($file->getPathname());
                    if ($imageInfo) {
                        $mediaMetadata[$index] = [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1],
                        ];
                    }
                } elseif (Str::startsWith($file->getMimeType(), 'video/')) {
                    $mediaFile['type'] = 'video';
                }

                $mediaFiles[] = $mediaFile;
            }

            $draft->media_files = $mediaFiles;
            $draft->media_metadata = $mediaMetadata;
        }

        // Handle audio file
        if ($request->hasFile('audio')) {
            // Delete old audio if exists
            if ($draft->audio_path) {
                Storage::disk('public')->delete($draft->audio_path);
            }

            $audioFile = $request->file('audio');
            $audioPath = $audioFile->store('drafts/' . $userId . '/audio', 'public');
            $draft->audio_path = $audioPath;

            // Process audio
            $fullPath = Storage::disk('public')->path($audioPath);
            $draft->audio_duration = $this->audioService->getAudioDuration($fullPath);
            $draft->audio_waveform = $this->audioService->generateWaveform($fullPath);
        }

        // Handle cover image
        if ($request->hasFile('cover_image')) {
            // Delete old cover if exists
            if ($draft->cover_image_path) {
                Storage::disk('public')->delete($draft->cover_image_path);
            }

            $coverFile = $request->file('cover_image');
            $draft->cover_image_path = $coverFile->store('drafts/' . $userId . '/covers', 'public');
        }

        // Update metadata
        $draft->last_edited_at = now();
        if ($draft->exists) {
            $draft->auto_save_version++;
        }

        $draft->save();

        return response()->json([
            'success' => true,
            'message' => 'Draft saved successfully',
            'data' => $draft->fresh(['musicTrack']),
        ], $draft->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Delete a draft.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $draft = PostDraft::forUser((int) $userId)->findOrFail($id);

        // Delete associated files
        $this->deleteDraftFiles($draft);

        $draft->delete();

        return response()->json([
            'success' => true,
            'message' => 'Draft deleted successfully',
        ]);
    }

    /**
     * Publish a draft as a post.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $userId = $request->input('user_id') ?? $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $draft = PostDraft::forUser((int) $userId)
            ->with('musicTrack')
            ->findOrFail($id);

        // Validate that draft has required content
        if (!$this->isDraftPublishable($draft)) {
            return response()->json([
                'success' => false,
                'message' => 'Draft is not ready to publish. Add content or media.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Determine post status
            $status = $draft->scheduled_at ? 'scheduled' : 'published';
            $publishedAt = $draft->scheduled_at ? null : now();

            // Create post
            $post = new Post();
            $post->user_id = $userId;
            $post->post_type = $draft->post_type;
            $post->content = $draft->content;
            $post->background_color = $draft->background_color;
            $post->privacy = $draft->privacy;
            $post->location_name = $draft->location_name;
            $post->location_lat = $draft->location_lat;
            $post->location_lng = $draft->location_lng;
            $post->status = $status;
            $post->scheduled_at = $draft->scheduled_at;
            $post->published_at = $publishedAt;
            $post->draft_id = $draft->id;

            // Audio fields
            $post->audio_duration = $draft->audio_duration;
            $post->audio_waveform = $draft->audio_waveform;

            // Video fields
            $post->music_track_id = $draft->music_track_id;
            $post->music_start_time = $draft->music_start_time;
            $post->original_audio_volume = $draft->original_audio_volume;
            $post->music_volume = $draft->music_volume;
            $post->video_speed = $draft->video_speed;
            $post->text_overlays = $draft->text_overlays;
            $post->video_filter = $draft->video_filter;

            $post->save();

            // Move media files from drafts to posts directory
            if (!empty($draft->media_files)) {
                foreach ($draft->media_files as $index => $mediaFile) {
                    $oldPath = $mediaFile['path'];
                    $newPath = str_replace('drafts/', 'posts/', $oldPath);

                    // Create directory if needed
                    $newDir = dirname($newPath);
                    if (!Storage::disk('public')->exists($newDir)) {
                        Storage::disk('public')->makeDirectory($newDir);
                    }

                    // Move file
                    Storage::disk('public')->move($oldPath, $newPath);

                    // Create post media record
                    $post->media()->create([
                        'file_path' => $newPath,
                        'file_url' => Storage::disk('public')->url($newPath),
                        'media_type' => $mediaFile['type'] ?? 'image',
                        'original_filename' => $mediaFile['original_name'] ?? null,
                        'mime_type' => $mediaFile['mime_type'] ?? null,
                        'file_size' => $mediaFile['size'] ?? null,
                        'width' => $draft->media_metadata[$index]['width'] ?? null,
                        'height' => $draft->media_metadata[$index]['height'] ?? null,
                        'order' => $index,
                    ]);
                }
            }

            // Move audio file
            if ($draft->audio_path) {
                $newAudioPath = str_replace('drafts/', 'posts/', $draft->audio_path);
                Storage::disk('public')->move($draft->audio_path, $newAudioPath);
                $post->audio_path = $newAudioPath;
            }

            // Move cover image
            if ($draft->cover_image_path) {
                $newCoverPath = str_replace('drafts/', 'posts/', $draft->cover_image_path);
                Storage::disk('public')->move($draft->cover_image_path, $newCoverPath);
                $post->cover_image_path = $newCoverPath;
            }

            $post->save();

            // Handle tagged users
            if (!empty($draft->tagged_users)) {
                $post->taggedUsers()->attach($draft->tagged_users);
            }

            // Delete draft (files already moved)
            $draft->delete();

            DB::commit();

            $message = $status === 'scheduled'
                ? 'Post scheduled successfully for ' . $draft->scheduled_at->format('M d, Y \a\t g:i A')
                : 'Post published successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $post->fresh(['user', 'media', 'taggedUsers', 'musicTrack']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to publish draft: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Duplicate a draft.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $original = PostDraft::forUser((int) $userId)->findOrFail($id);

        $duplicate = $original->replicate();
        $duplicate->title = ($original->title ?? 'Draft') . ' (Copy)';
        $duplicate->auto_save_version = 1;
        $duplicate->last_edited_at = now();
        $duplicate->scheduled_at = null;
        $duplicate->save();

        return response()->json([
            'success' => true,
            'message' => 'Draft duplicated successfully',
            'data' => $duplicate,
        ], 201);
    }

    /**
     * Get draft counts by type.
     */
    public function counts(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $counts = PostDraft::forUser((int) $userId)
            ->select('post_type', DB::raw('count(*) as count'))
            ->groupBy('post_type')
            ->pluck('count', 'post_type')
            ->toArray();

        $scheduled = PostDraft::forUser((int) $userId)->scheduled()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => array_sum($counts),
                'by_type' => $counts,
                'scheduled' => $scheduled,
            ],
        ]);
    }

    /**
     * Delete all drafts for user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $userId = $request->query('user_id') ?? $request->input('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $drafts = PostDraft::forUser((int) $userId)->get();

        foreach ($drafts as $draft) {
            $this->deleteDraftFiles($draft);
            $draft->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'All drafts deleted successfully',
        ]);
    }

    /**
     * Check if draft has enough content to publish.
     */
    private function isDraftPublishable(PostDraft $draft): bool
    {
        switch ($draft->post_type) {
            case PostDraft::TYPE_TEXT:
                return !empty($draft->content);

            case PostDraft::TYPE_PHOTO:
            case PostDraft::TYPE_VIDEO:
            case PostDraft::TYPE_SHORT_VIDEO:
                return !empty($draft->media_files);

            case PostDraft::TYPE_AUDIO:
                return !empty($draft->audio_path);

            default:
                return false;
        }
    }

    /**
     * Delete files associated with a draft.
     */
    private function deleteDraftFiles(PostDraft $draft): void
    {
        // Delete media files
        if (!empty($draft->media_files)) {
            foreach ($draft->media_files as $mediaFile) {
                if (isset($mediaFile['path'])) {
                    Storage::disk('public')->delete($mediaFile['path']);
                }
                if (isset($mediaFile['thumbnail'])) {
                    Storage::disk('public')->delete($mediaFile['thumbnail']);
                }
            }
        }

        // Delete audio
        if ($draft->audio_path) {
            Storage::disk('public')->delete($draft->audio_path);
        }

        // Delete cover image
        if ($draft->cover_image_path) {
            Storage::disk('public')->delete($draft->cover_image_path);
        }
    }
}
