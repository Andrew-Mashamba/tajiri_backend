<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clip;
use App\Models\ClipComment;
use App\Models\ClipHashtag;
use App\Models\ClipShare;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ClipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 10);
        $currentUserId = $request->query('current_user_id');

        $clips = Clip::with([
            'user:id,first_name,last_name,username,profile_photo_path',
            'music.artist',
        ])
            ->forYou()
            ->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($clips as $clip) {
                $clip->is_liked = $clip->isLikedBy($currentUserId);
                $clip->is_saved = $clip->isSavedBy($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $clips->items(),
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page' => $clips->lastPage(),
                'total' => $clips->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'video' => 'required|file|mimetypes:video/*|max:102400',
            'caption' => 'nullable|string|max:2000',
            'music_id' => 'nullable|exists:music_tracks,id',
            'music_start' => 'nullable|integer',
            'hashtags' => 'nullable|array',
            'mentions' => 'nullable|array',
            'location_name' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'privacy' => 'nullable|in:public,friends,private',
            'allow_comments' => 'nullable|boolean',
            'allow_duet' => 'nullable|boolean',
            'allow_stitch' => 'nullable|boolean',
            'allow_download' => 'nullable|boolean',
            'original_clip_id' => 'nullable|exists:clips,id',
            'clip_type' => 'nullable|in:original,duet,stitch',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $videoFile = $request->file('video');
            $videoPath = $videoFile->store('clips/' . $request->user_id, 'public');

            // Get video duration (simplified - use FFmpeg in production)
            $duration = 15; // placeholder

            $clip = Clip::create([
                'user_id' => $request->user_id,
                'video_path' => $videoPath,
                'caption' => $request->caption,
                'duration' => $duration,
                'music_id' => $request->music_id,
                'music_start' => $request->music_start,
                'hashtags' => $request->hashtags,
                'mentions' => $request->mentions,
                'location_name' => $request->location_name,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'privacy' => $request->privacy ?? 'public',
                'allow_comments' => $request->allow_comments ?? true,
                'allow_duet' => $request->allow_duet ?? true,
                'allow_stitch' => $request->allow_stitch ?? true,
                'allow_download' => $request->allow_download ?? true,
                'original_clip_id' => $request->original_clip_id,
                'clip_type' => $request->clip_type ?? 'original',
                'status' => 'published',
            ]);

            // Process hashtags
            if ($request->hashtags) {
                foreach ($request->hashtags as $tag) {
                    $hashtag = ClipHashtag::firstOrCreate(
                        ['tag' => strtolower(trim($tag, '#'))],
                        ['tag' => strtolower(trim($tag, '#'))]
                    );
                    $hashtag->increment('clips_count');
                    $clip->hashtagRelations()->attach($hashtag->id);
                }
            }

            // Update music uses count
            if ($request->music_id) {
                $clip->music->incrementUses();
            }

            $clip->load(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist']);

            return response()->json([
                'success' => true,
                'message' => 'Clip created',
                'data' => $clip,
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $clip = Clip::with([
            'user:id,first_name,last_name,username,profile_photo_path',
            'music.artist',
            'originalClip.user:id,first_name,last_name,username,profile_photo_path',
        ])->find($id);

        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        $clip->incrementViews();

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $clip->is_liked = $clip->isLikedBy($currentUserId);
            $clip->is_saved = $clip->isSavedBy($currentUserId);
        }

        return response()->json(['success' => true, 'data' => $clip]);
    }

    public function destroy(int $id): JsonResponse
    {
        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        Storage::disk('public')->delete($clip->video_path);
        $clip->delete();

        return response()->json(['success' => true, 'message' => 'Clip deleted']);
    }

    public function userClips(int $userId, Request $request): JsonResponse
    {
        $clips = Clip::with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->where('user_id', $userId)
            ->published()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $clips->items(),
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page' => $clips->lastPage(),
                'total' => $clips->total(),
            ],
        ]);
    }

    public function like(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        if (!$clip->isLikedBy($request->user_id)) {
            $clip->likes()->attach($request->user_id);
            $clip->increment('likes_count');
        }

        return response()->json(['success' => true, 'message' => 'Liked']);
    }

    public function unlike(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        if ($clip->isLikedBy($request->user_id)) {
            $clip->likes()->detach($request->user_id);
            $clip->decrement('likes_count');
        }

        return response()->json(['success' => true, 'message' => 'Unliked']);
    }

    public function save(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        if (!$clip->isSavedBy($request->user_id)) {
            $clip->saves()->attach($request->user_id);
            $clip->increment('saves_count');
        }

        return response()->json(['success' => true, 'message' => 'Saved']);
    }

    public function unsave(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        $clip->saves()->detach($request->user_id);
        $clip->decrement('saves_count');

        return response()->json(['success' => true, 'message' => 'Unsaved']);
    }

    public function share(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'platform' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        ClipShare::create([
            'clip_id' => $id,
            'user_id' => $request->user_id,
            'platform' => $request->platform,
        ]);

        $clip->increment('shares_count');

        return response()->json(['success' => true, 'message' => 'Shared']);
    }

    // Comments
    public function comments(int $id, Request $request): JsonResponse
    {
        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        $comments = $clip->comments()
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'replies.user:id,first_name,last_name,username,profile_photo_path',
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    public function addComment(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:clip_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $clip = Clip::find($id);
        if (!$clip) {
            return response()->json(['success' => false, 'message' => 'Clip not found'], 404);
        }

        if (!$clip->allow_comments) {
            return response()->json(['success' => false, 'message' => 'Comments disabled'], 400);
        }

        $comment = ClipComment::create([
            'clip_id' => $id,
            'user_id' => $request->user_id,
            'parent_id' => $request->parent_id,
            'content' => $request->content,
        ]);

        $clip->increment('comments_count');

        if ($request->parent_id) {
            ClipComment::find($request->parent_id)->increment('replies_count');
        }

        $comment->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json(['success' => true, 'data' => $comment], 201);
    }

    public function likeComment(int $clipId, int $commentId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $comment = ClipComment::find($commentId);
        if (!$comment || $comment->clip_id != $clipId) {
            return response()->json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        if (!$comment->isLikedBy($request->user_id)) {
            $comment->likes()->attach($request->user_id);
            $comment->increment('likes_count');
        }

        return response()->json(['success' => true, 'message' => 'Comment liked']);
    }

    // Hashtags
    public function trending(): JsonResponse
    {
        $hashtags = ClipHashtag::trending()->limit(20)->get();

        return response()->json(['success' => true, 'data' => $hashtags]);
    }

    public function byHashtag(string $tag, Request $request): JsonResponse
    {
        $hashtag = ClipHashtag::where('tag', strtolower($tag))->first();

        if (!$hashtag) {
            return response()->json(['success' => true, 'data' => [], 'hashtag' => null]);
        }

        $clips = $hashtag->clips()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->published()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $clips->items(),
            'hashtag' => $hashtag,
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page' => $clips->lastPage(),
                'total' => $clips->total(),
            ],
        ]);
    }

    public function byMusic(int $musicId, Request $request): JsonResponse
    {
        $clips = Clip::with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->where('music_id', $musicId)
            ->published()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $clips->items(),
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page' => $clips->lastPage(),
                'total' => $clips->total(),
            ],
        ]);
    }
}
