<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\StreamViewer;
use App\Models\StreamComment;
use App\Models\StreamGift;
use App\Models\StreamCohost;
use App\Models\VirtualGift;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LiveStreamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'live');
        $category = $request->query('category');

        $query = LiveStream::with('user:id,first_name,last_name,username,profile_photo_path');

        if ($status === 'live') {
            $query->live();
        } elseif ($status === 'scheduled') {
            $query->scheduled()->where('scheduled_at', '>', now());
        } elseif ($status === 'ended') {
            $query->ended();
        }

        if ($category) {
            $query->where('category', $category);
        }

        $streams = $query->where('privacy', 'public')
            ->orderByDesc('viewers_count')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $streams->items(),
            'meta' => [
                'current_page' => $streams->currentPage(),
                'last_page' => $streams->lastPage(),
                'total' => $streams->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'thumbnail' => 'nullable|file|image|max:5120',
            'category' => 'nullable|string|max:50',
            'tags' => 'nullable|array',
            'privacy' => 'nullable|in:public,friends,private',
            'scheduled_at' => 'nullable|date|after:now',
            'allow_comments' => 'nullable|boolean',
            'allow_gifts' => 'nullable|boolean',
            'is_recorded' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $thumbnailPath = $request->hasFile('thumbnail')
            ? $request->file('thumbnail')->store('streams/' . $request->user_id, 'public')
            : null;

        $stream = LiveStream::create([
            'user_id' => $request->user_id,
            'title' => $request->title,
            'description' => $request->description,
            'thumbnail_path' => $thumbnailPath,
            'category' => $request->category,
            'tags' => $request->tags,
            'privacy' => $request->privacy ?? 'public',
            'status' => $request->scheduled_at ? 'scheduled' : 'live',
            'scheduled_at' => $request->scheduled_at,
            'started_at' => $request->scheduled_at ? null : now(),
            'allow_comments' => $request->allow_comments ?? true,
            'allow_gifts' => $request->allow_gifts ?? true,
            'is_recorded' => $request->is_recorded ?? true,
        ]);

        $stream->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => 'Stream created',
            'data' => $stream,
        ], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $stream = LiveStream::with([
            'user:id,first_name,last_name,username,profile_photo_path',
            'cohosts.user:id,first_name,last_name,username,profile_photo_path',
        ])->find($id);

        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $stream->is_liked = $stream->likes()->where('user_id', $currentUserId)->exists();
        }

        return response()->json(['success' => true, 'data' => $stream]);
    }

    public function start(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $stream->start();

        return response()->json(['success' => true, 'message' => 'Stream started', 'data' => $stream]);
    }

    public function end(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $stream->end();

        return response()->json(['success' => true, 'message' => 'Stream ended', 'data' => $stream]);
    }

    public function join(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        if (!$stream->isLive()) {
            return response()->json(['success' => false, 'message' => 'Stream not live'], 400);
        }

        // Check for existing viewer record
        $viewer = StreamViewer::where('stream_id', $id)
            ->where('user_id', $request->user_id)
            ->whereNull('left_at')
            ->first();

        if (!$viewer) {
            StreamViewer::create([
                'stream_id' => $id,
                'user_id' => $request->user_id,
                'joined_at' => now(),
            ]);

            $stream->increment('total_viewers');
        }

        $stream->updateViewerCount();

        return response()->json(['success' => true, 'message' => 'Joined stream']);
    }

    public function leave(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $viewer = StreamViewer::where('stream_id', $id)
            ->where('user_id', $request->user_id)
            ->whereNull('left_at')
            ->first();

        if ($viewer) {
            $watchDuration = now()->diffInSeconds($viewer->joined_at);
            $viewer->update([
                'left_at' => now(),
                'watch_duration' => $watchDuration,
            ]);

            $stream = LiveStream::find($id);
            $stream->updateViewerCount();
        }

        return response()->json(['success' => true, 'message' => 'Left stream']);
    }

    public function like(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $stream->likes()->syncWithoutDetaching([$request->user_id]);
        $stream->update(['likes_count' => $stream->likes()->count()]);

        return response()->json(['success' => true, 'message' => 'Liked']);
    }

    // Comments
    public function comments(int $id, Request $request): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $after = $request->query('after'); // timestamp for polling

        $query = $stream->comments()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at');

        if ($after) {
            $query->where('created_at', '>', $after);
        } else {
            $query->limit(100);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function addComment(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        if (!$stream->allow_comments) {
            return response()->json(['success' => false, 'message' => 'Comments disabled'], 400);
        }

        $comment = StreamComment::create([
            'stream_id' => $id,
            'user_id' => $request->user_id,
            'content' => $request->content,
        ]);

        $stream->increment('comments_count');

        $comment->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json(['success' => true, 'data' => $comment], 201);
    }

    public function pinComment(int $id, int $commentId): JsonResponse
    {
        $comment = StreamComment::where('stream_id', $id)->where('id', $commentId)->first();

        if (!$comment) {
            return response()->json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        // Unpin other comments
        StreamComment::where('stream_id', $id)->update(['is_pinned' => false]);

        $comment->update(['is_pinned' => true]);

        return response()->json(['success' => true, 'message' => 'Comment pinned']);
    }

    // Gifts
    public function gifts(): JsonResponse
    {
        $gifts = VirtualGift::active()->get();

        return response()->json(['success' => true, 'data' => $gifts]);
    }

    public function sendGift(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'gift_id' => 'required|exists:virtual_gifts,id',
            'quantity' => 'nullable|integer|min:1|max:100',
            'message' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        if (!$stream->allow_gifts) {
            return response()->json(['success' => false, 'message' => 'Gifts disabled'], 400);
        }

        $gift = VirtualGift::find($request->gift_id);
        $quantity = $request->quantity ?? 1;
        $totalValue = $gift->price * $quantity;

        $streamGift = StreamGift::create([
            'stream_id' => $id,
            'sender_id' => $request->user_id,
            'gift_id' => $request->gift_id,
            'quantity' => $quantity,
            'total_value' => $totalValue,
            'message' => $request->message,
        ]);

        $stream->increment('gifts_count', $quantity);
        $stream->increment('gifts_value', $totalValue);

        $streamGift->load(['sender:id,first_name,last_name,username,profile_photo_path', 'gift']);

        return response()->json(['success' => true, 'data' => $streamGift], 201);
    }

    public function streamGifts(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $gifts = $stream->gifts()
            ->with(['sender:id,first_name,last_name,username,profile_photo_path', 'gift'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $gifts]);
    }

    // Co-hosts
    public function inviteCohost(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $cohost = StreamCohost::updateOrCreate(
            ['stream_id' => $id, 'user_id' => $request->user_id],
            ['status' => 'invited']
        );

        return response()->json(['success' => true, 'message' => 'Invitation sent', 'data' => $cohost]);
    }

    public function respondCohost(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'accept' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $cohost = StreamCohost::where('stream_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$cohost) {
            return response()->json(['success' => false, 'message' => 'Invitation not found'], 404);
        }

        $cohost->update([
            'status' => $request->accept ? 'active' : 'declined',
            'joined_at' => $request->accept ? now() : null,
        ]);

        return response()->json(['success' => true, 'message' => $request->accept ? 'Joined as cohost' : 'Declined']);
    }

    public function leaveCohost(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $cohost = StreamCohost::where('stream_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($cohost) {
            $cohost->update([
                'status' => 'ended',
                'left_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Left co-hosting']);
    }

    // User streams
    public function userStreams(int $userId, Request $request): JsonResponse
    {
        $status = $request->query('status'); // live, scheduled, ended, or all

        $query = LiveStream::with('user:id,first_name,last_name,username,profile_photo_path')
            ->where('user_id', $userId);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $streams = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $streams->items(),
            'meta' => [
                'current_page' => $streams->currentPage(),
                'last_page' => $streams->lastPage(),
                'total' => $streams->total(),
            ],
        ]);
    }

    public function viewers(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $viewers = $stream->viewers()
            ->whereNull('left_at')
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->get();

        return response()->json(['success' => true, 'data' => $viewers]);
    }
}
