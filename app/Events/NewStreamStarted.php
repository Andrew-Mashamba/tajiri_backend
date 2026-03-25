<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a new stream goes live.
 * Used to add new streams to the Live tab grid in real-time.
 *
 * Broadcasts on public 'live-streams' channel.
 */
class NewStreamStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public LiveStream $stream;

    public function __construct(LiveStream $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Broadcast on public channel for ALL users.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('live-streams');
    }

    /**
     * The event name for frontend matching.
     */
    public function broadcastAs(): string
    {
        return 'new_stream_started';
    }

    /**
     * Full stream data for adding to grid.
     */
    public function broadcastWith(): array
    {
        return [
            'stream' => [
                'id' => $this->stream->id,
                'stream_key' => $this->stream->stream_key,
                'title' => $this->stream->title,
                'description' => $this->stream->description,
                'thumbnail_path' => $this->stream->thumbnail_path,
                'category' => $this->stream->category,
                'tags' => $this->stream->tags,
                'status' => $this->stream->status,
                'privacy' => $this->stream->privacy,
                'viewers_count' => $this->stream->viewers_count ?? 0,
                'peak_viewers' => $this->stream->peak_viewers ?? 0,
                'likes_count' => $this->stream->likes_count ?? 0,
                'started_at' => $this->stream->started_at?->toIso8601String(),
                'playback_url' => $this->stream->playback_url,
                'user' => [
                    'id' => $this->stream->user->id,
                    'first_name' => $this->stream->user->first_name,
                    'last_name' => $this->stream->user->last_name,
                    'username' => $this->stream->user->username,
                    'profile_photo_path' => $this->stream->user->profile_photo_path,
                ],
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
