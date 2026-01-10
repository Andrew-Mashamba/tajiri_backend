<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryHighlight;
use App\Models\StoryReaction;
use App\Models\StoryReply;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentUserId = $request->query('current_user_id');

        $stories = Story::with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->active()
            ->where('privacy', 'everyone')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $grouped = [];
        foreach ($stories as $userId => $userStories) {
            $user = $userStories->first()->user;
            $grouped[] = [
                'user' => $user,
                'stories' => $userStories->map(function ($story) use ($currentUserId) {
                    $story->has_viewed = $currentUserId ? $story->hasViewed($currentUserId) : false;
                    return $story;
                }),
                'has_unviewed' => $currentUserId ? $userStories->contains(fn($s) => !$s->hasViewed($currentUserId)) : true,
            ];
        }

        return response()->json(['success' => true, 'data' => $grouped]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'media_type' => 'required|in:image,video,text',
            'media' => 'required_unless:media_type,text|file|max:51200',
            'caption' => 'nullable|string|max:500',
            'duration' => 'nullable|integer|min:1|max:30',
            'text_overlays' => 'nullable|array',
            'stickers' => 'nullable|array',
            'filter' => 'nullable|string',
            'music_id' => 'nullable|exists:music_tracks,id',
            'music_start' => 'nullable|integer',
            'background_color' => 'nullable|string',
            'link_url' => 'nullable|url',
            'location_name' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'privacy' => 'nullable|in:everyone,friends,close_friends',
            'allow_replies' => 'nullable|boolean',
            'allow_sharing' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $mediaPath = null;
            $thumbnailPath = null;

            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mediaPath = $file->store('stories/' . $request->user_id, 'public');

                if ($request->media_type === 'video') {
                    // Generate thumbnail for video (simplified - in production use FFmpeg)
                    $thumbnailPath = $mediaPath; // placeholder
                }
            }

            $story = Story::create([
                'user_id' => $request->user_id,
                'media_type' => $request->media_type,
                'media_path' => $mediaPath,
                'thumbnail_path' => $thumbnailPath,
                'caption' => $request->caption,
                'duration' => $request->duration ?? ($request->media_type === 'video' ? 15 : 5),
                'text_overlays' => $request->text_overlays,
                'stickers' => $request->stickers,
                'filter' => $request->filter,
                'music_id' => $request->music_id,
                'music_start' => $request->music_start,
                'background_color' => $request->background_color,
                'link_url' => $request->link_url,
                'location_name' => $request->location_name,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'privacy' => $request->privacy ?? 'everyone',
                'allow_replies' => $request->allow_replies ?? true,
                'allow_sharing' => $request->allow_sharing ?? true,
                'expires_at' => now()->addHours(24),
            ]);

            $story->load(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist']);

            return response()->json([
                'success' => true,
                'message' => 'Story created',
                'data' => $story,
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $story = Story::with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->find($id);

        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $story->markViewed($currentUserId);
            $story->has_viewed = true;
        }

        return response()->json(['success' => true, 'data' => $story]);
    }

    public function destroy(int $id): JsonResponse
    {
        $story = Story::find($id);
        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        if ($story->media_path) {
            Storage::disk('public')->delete($story->media_path);
        }
        $story->delete();

        return response()->json(['success' => true, 'message' => 'Story deleted']);
    }

    public function userStories(int $userId, Request $request): JsonResponse
    {
        $stories = Story::with(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist'])
            ->where('user_id', $userId)
            ->active()
            ->orderBy('created_at')
            ->get();

        $currentUserId = $request->query('current_user_id');
        foreach ($stories as $story) {
            $story->has_viewed = $currentUserId ? $story->hasViewed($currentUserId) : false;
        }

        return response()->json(['success' => true, 'data' => $stories]);
    }

    public function view(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $story = Story::find($id);
        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        $story->markViewed($request->user_id);

        return response()->json(['success' => true, 'message' => 'View recorded']);
    }

    public function viewers(int $id): JsonResponse
    {
        $story = Story::find($id);
        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        $viewers = $story->views()
            ->with('viewer:id,first_name,last_name,username,profile_photo_path')
            ->orderByDesc('viewed_at')
            ->get();

        return response()->json(['success' => true, 'data' => $viewers]);
    }

    public function react(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'emoji' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $story = Story::find($id);
        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        StoryReaction::updateOrCreate(
            ['story_id' => $id, 'user_id' => $request->user_id],
            ['emoji' => $request->emoji]
        );

        $story->update(['reactions_count' => $story->reactions()->count()]);

        return response()->json(['success' => true, 'message' => 'Reaction added']);
    }

    public function reply(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $story = Story::find($id);
        if (!$story) {
            return response()->json(['success' => false, 'message' => 'Story not found'], 404);
        }

        if (!$story->allow_replies) {
            return response()->json(['success' => false, 'message' => 'Replies disabled'], 400);
        }

        $reply = StoryReply::create([
            'story_id' => $id,
            'user_id' => $request->user_id,
            'content' => $request->content,
        ]);

        $reply->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json(['success' => true, 'data' => $reply], 201);
    }

    // Highlights
    public function highlights(int $userId): JsonResponse
    {
        $highlights = StoryHighlight::where('user_id', $userId)
            ->with(['stories' => function ($q) {
                $q->withTrashed();
            }])
            ->orderBy('order')
            ->get();

        return response()->json(['success' => true, 'data' => $highlights]);
    }

    public function createHighlight(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'title' => 'required|string|max:50',
            'story_ids' => 'required|array|min:1',
            'cover' => 'nullable|file|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $coverPath = null;
        if ($request->hasFile('cover')) {
            $coverPath = $request->file('cover')->store('highlights/' . $request->user_id, 'public');
        }

        $highlight = StoryHighlight::create([
            'user_id' => $request->user_id,
            'title' => $request->title,
            'cover_path' => $coverPath,
        ]);

        $highlight->stories()->attach($request->story_ids);

        return response()->json(['success' => true, 'data' => $highlight->load('stories')], 201);
    }

    public function updateHighlight(int $id, Request $request): JsonResponse
    {
        $highlight = StoryHighlight::find($id);
        if (!$highlight) {
            return response()->json(['success' => false, 'message' => 'Highlight not found'], 404);
        }

        if ($request->has('title')) {
            $highlight->update(['title' => $request->title]);
        }

        if ($request->has('story_ids')) {
            $highlight->stories()->sync($request->story_ids);
        }

        return response()->json(['success' => true, 'data' => $highlight->load('stories')]);
    }

    public function deleteHighlight(int $id): JsonResponse
    {
        $highlight = StoryHighlight::find($id);
        if (!$highlight) {
            return response()->json(['success' => false, 'message' => 'Highlight not found'], 404);
        }

        $highlight->delete();

        return response()->json(['success' => true, 'message' => 'Highlight deleted']);
    }
}
