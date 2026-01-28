<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\StreamViewer;
use App\Models\StreamComment;
use App\Models\StreamGift;
use App\Models\StreamCohost;
use App\Models\StreamNotification;
use App\Models\StreamAnalytics;
use App\Models\VirtualGift;
use App\Events\StreamStatusChanged;
use App\Events\ViewerCountUpdated;
use App\Events\NewStreamComment;
use App\Events\GiftReceived;
use App\Events\CoHostJoined;
use App\Events\StreamEnded;
use App\Jobs\GenerateStreamAnalytics;
use App\Services\WebSocket\WebSocketBroadcaster;
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
        } elseif ($status === 'pre_live') {
            $query->preLive();
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
            'allow_co_hosts' => 'nullable|boolean',
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
            'status' => $request->scheduled_at ? 'scheduled' : 'scheduled',
            'scheduled_at' => $request->scheduled_at,
            'allow_comments' => $request->allow_comments ?? true,
            'allow_gifts' => $request->allow_gifts ?? true,
            'allow_co_hosts' => $request->allow_co_hosts ?? false,
            'is_recorded' => $request->is_recorded ?? true,
            'stream_url' => null, // Set by streaming infrastructure
            'playback_url' => null,
        ]);

        $stream->load('user:id,first_name,last_name,username,profile_photo_path');

        // Notify followers of scheduled stream
        if ($stream->scheduled_at) {
            $this->notifyFollowers($stream, 'scheduled');
        }

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

    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pre_live,live,ending,ended,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $newStatus = $request->status;
        $oldStatus = $stream->status;

        if (!$stream->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$oldStatus}' to '{$newStatus}'",
            ], 422);
        }

        $stream->transitionTo($newStatus);

        // Pusher/Echo broadcast
        broadcast(new StreamStatusChanged($stream));
        // Plain WebSocket broadcast
        WebSocketBroadcaster::statusChanged($id, $oldStatus, $newStatus);

        // Send notifications based on status
        if ($newStatus === 'pre_live') {
            $this->notifyFollowers($stream, 'starting_soon');
        } elseif ($newStatus === 'live') {
            $this->notifyFollowers($stream, 'now_live');
        } elseif ($newStatus === 'ended') {
            $this->notifyViewers($stream, 'ended');
        }

        return response()->json([
            'success' => true,
            'message' => "Stream moved to {$newStatus} status.",
            'data' => $stream->fresh(),
        ]);
    }

    public function start(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $oldStatus = $stream->status;

        if (!$stream->canTransitionTo('live')) {
            return response()->json([
                'success' => false,
                'message' => "Cannot start stream from '{$oldStatus}' status",
            ], 422);
        }

        $stream->start();

        broadcast(new StreamStatusChanged($stream));
        WebSocketBroadcaster::statusChanged($id, $oldStatus, 'live');
        $this->notifyFollowers($stream, 'now_live');

        return response()->json([
            'success' => true,
            'message' => 'Stream started',
            'data' => $stream,
            'playback_url' => $stream->playback_url,
        ]);
    }

    public function end(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $oldStatus = $stream->status;

        if (!$stream->canTransitionTo('ending')) {
            return response()->json([
                'success' => false,
                'message' => "Cannot end stream from '{$oldStatus}' status",
            ], 422);
        }

        $stream->end(); // transitions to 'ending'

        broadcast(new StreamStatusChanged($stream));
        WebSocketBroadcaster::statusChanged($id, $oldStatus, 'ending');

        // The TransitionToEnded job will finalize after 5 seconds
        // But also return current analytics
        $analytics = [
            'total_viewers' => $stream->total_viewers,
            'peak_viewers' => $stream->peak_viewers,
            'total_gifts' => $stream->gifts_count,
            'revenue' => (float) $stream->gifts_value,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Stream ending',
            'data' => $stream,
            'analytics' => $analytics,
        ]);
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

        if (!in_array($stream->status, ['live', 'pre_live'])) {
            return response()->json(['success' => false, 'message' => 'Stream not active'], 400);
        }

        // Check for existing active viewer record
        $viewer = StreamViewer::where('stream_id', $id)
            ->where('user_id', $request->user_id)
            ->where('is_currently_watching', true)
            ->first();

        if (!$viewer) {
            StreamViewer::create([
                'stream_id' => $id,
                'user_id' => $request->user_id,
                'joined_at' => now(),
                'is_currently_watching' => true,
            ]);

            $stream->increment('total_viewers');
        }

        $stream->updateViewerCount();

        broadcast(new ViewerCountUpdated($stream));
        WebSocketBroadcaster::viewerCountUpdated($id, $stream->viewers_count, $stream->peak_viewers);

        return response()->json([
            'success' => true,
            'message' => 'Joined stream',
            'playback_url' => $stream->playback_url,
            'current_viewers' => $stream->viewers_count,
        ]);
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
            ->where('is_currently_watching', true)
            ->first();

        if ($viewer) {
            $watchDuration = now()->diffInSeconds($viewer->joined_at);
            $viewer->update([
                'left_at' => now(),
                'watch_duration' => $watchDuration,
                'is_currently_watching' => false,
            ]);

            $stream = LiveStream::find($id);
            if ($stream) {
                $stream->updateViewerCount();
                broadcast(new ViewerCountUpdated($stream));
                WebSocketBroadcaster::viewerCountUpdated($id, $stream->viewers_count, $stream->peak_viewers);
            }
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

        $exists = $stream->likes()->where('user_id', $request->user_id)->exists();

        if ($exists) {
            $stream->likes()->detach($request->user_id);
            $liked = false;
        } else {
            $stream->likes()->attach($request->user_id);
            $liked = true;
        }

        $stream->update(['likes_count' => $stream->likes()->count()]);

        return response()->json([
            'success' => true,
            'message' => $liked ? 'Liked' : 'Unliked',
            'liked' => $liked,
        ]);
    }

    public function reaction(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'reaction_type' => 'required|string|in:heart,fire,love,wow,clap,laugh',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        // Update reaction counts
        $counts = $stream->reaction_counts ?? [];
        $counts[$request->reaction_type] = ($counts[$request->reaction_type] ?? 0) + 1;
        $stream->update(['reaction_counts' => $counts]);

        // Broadcast via plain WebSocket
        WebSocketBroadcaster::reaction($id, $request->user_id, $request->reaction_type);

        return response()->json(['success' => true, 'message' => 'Reaction sent']);
    }

    // Comments
    public function comments(int $id, Request $request): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $lastId = $request->query('last_id');
        $limit = min((int) $request->query('limit', 50), 100);

        $query = $stream->comments()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at');

        if ($lastId) {
            $query->where('id', '>', $lastId);
        }

        return response()->json(['success' => true, 'data' => $query->limit($limit)->get()]);
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

        broadcast(new NewStreamComment($comment));

        // Plain WebSocket broadcast
        $user = $comment->user;
        WebSocketBroadcaster::newComment($id, [
            'id' => $comment->id,
            'user_id' => $comment->user_id,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => trim($user->first_name . ' ' . $user->last_name),
                'avatar_url' => $user->profile_photo_path,
            ],
            'content' => $comment->content,
            'created_at' => $comment->created_at->toIso8601String(),
        ]);

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
        $gifts = VirtualGift::where('is_active', true)->orderBy('order')->get();

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

        broadcast(new GiftReceived($streamGift));

        // Plain WebSocket broadcast
        $sender = $streamGift->sender;
        WebSocketBroadcaster::giftSent($id, [
            'sender' => [
                'id' => $sender->id,
                'first_name' => $sender->first_name,
                'last_name' => $sender->last_name,
                'display_name' => trim($sender->first_name . ' ' . $sender->last_name),
                'avatar_url' => $sender->profile_photo_path,
            ],
            'gift' => [
                'id' => $gift->id,
                'name' => $gift->name,
                'icon_url' => $gift->icon_path,
                'price' => (float) $gift->price,
            ],
            'quantity' => $quantity,
            'message' => $request->message,
        ]);

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

        if (!$stream->allow_co_hosts) {
            return response()->json(['success' => false, 'message' => 'Co-hosts not allowed'], 400);
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

        if ($request->accept) {
            broadcast(new CoHostJoined($cohost));
        }

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
        $status = $request->query('status');

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
            ->where('is_currently_watching', true)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->get();

        return response()->json(['success' => true, 'data' => $viewers]);
    }

    // Notifications
    public function notifyFollowersEndpoint(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $type = $stream->status === 'live' ? 'now_live' : 'scheduled';
        $count = $this->notifyFollowers($stream, $type);

        return response()->json([
            'success' => true,
            'message' => "Notifications sent to {$count} followers",
        ]);
    }

    public function streamNotifications(int $userId): JsonResponse
    {
        $notifications = StreamNotification::where('user_id', $userId)
            ->with(['stream.user:id,first_name,last_name,username,profile_photo_path'])
            ->orderByDesc('sent_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    // Analytics
    public function analytics(int $id): JsonResponse
    {
        $stream = LiveStream::find($id);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $uniqueViewers = $stream->viewers()->distinct('user_id')->count('user_id');
        $avgWatchTime = $stream->viewers()->avg('watch_duration') ?? 0;

        // Get retention data from analytics snapshots
        $retentionData = $stream->analytics()
            ->orderBy('timestamp')
            ->get()
            ->map(fn ($a) => [
                'timestamp' => $a->timestamp->diffInSeconds($stream->started_at ?? $stream->created_at),
                'viewers' => $a->viewers_count,
            ]);

        // Top viewers by watch time
        $topViewers = $stream->viewers()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderByDesc('watch_duration')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stream_id' => $stream->id,
                'total_viewers' => $stream->total_viewers,
                'unique_viewers' => $uniqueViewers,
                'peak_viewers' => $stream->peak_viewers,
                'average_watch_time' => round($avgWatchTime),
                'total_likes' => $stream->likes_count,
                'total_comments' => $stream->comments_count,
                'total_shares' => $stream->shares_count,
                'total_gifts' => $stream->gifts_count,
                'total_revenue' => (float) $stream->gifts_value,
                'duration' => $stream->duration,
                'retention_data' => $retentionData,
                'top_viewers' => $topViewers,
            ],
        ]);
    }

    // Helper: notify followers
    private function notifyFollowers(LiveStream $stream, string $type): int
    {
        $subscriberIds = DB::table('stream_subscriptions')
            ->where('streamer_id', $stream->user_id)
            ->where('notify_live', true)
            ->pluck('subscriber_id');

        if ($subscriberIds->isEmpty()) {
            return 0;
        }

        // Avoid duplicate notifications
        $alreadyNotified = StreamNotification::where('stream_id', $stream->id)
            ->where('type', $type)
            ->pluck('user_id');

        $toNotify = $subscriberIds->diff($alreadyNotified);

        $notifications = $toNotify->map(fn ($userId) => [
            'stream_id' => $stream->id,
            'user_id' => $userId,
            'type' => $type,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        if (!empty($notifications)) {
            StreamNotification::insert($notifications);
        }

        return count($notifications);
    }

    // Helper: notify viewers of ended stream
    private function notifyViewers(LiveStream $stream, string $type): void
    {
        $viewerIds = $stream->viewers()->distinct('user_id')->pluck('user_id');

        $notifications = $viewerIds->map(fn ($userId) => [
            'stream_id' => $stream->id,
            'user_id' => $userId,
            'type' => $type,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        if (!empty($notifications)) {
            StreamNotification::insert($notifications);
        }
    }
}
