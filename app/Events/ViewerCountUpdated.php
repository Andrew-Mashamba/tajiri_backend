<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast viewer count updates to viewers of a specific stream.
 * Uses ShouldBroadcastNow for immediate delivery (<50ms).
 */
class ViewerCountUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveStream $stream
    ) {}

    /**
     * Broadcast to stream-specific channel.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('stream.' . $this->stream->id),
        ];
    }

    /**
     * Event name for frontend matching.
     */
    public function broadcastAs(): string
    {
        return 'viewer_count_updated';
    }

    /**
     * Data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'stream_id' => $this->stream->id,
            'viewers_count' => $this->stream->viewers_count,
            'peak_viewers' => $this->stream->peak_viewers,
            'unique_viewers' => $this->stream->unique_viewers ?? 0,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
