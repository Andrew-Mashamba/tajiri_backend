<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast stream status changes to viewers of a specific stream.
 * Uses ShouldBroadcastNow for immediate delivery (<50ms).
 */
class StreamStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveStream $stream,
        public ?string $oldStatus = null
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
        return 'status_changed';
    }

    /**
     * Data to broadcast.
     */
    public function broadcastWith(): array
    {
        $data = [
            'stream_id' => $this->stream->id,
            'old_status' => $this->oldStatus,
            'status' => $this->stream->status,
            'started_at' => $this->stream->started_at?->toIso8601String(),
            'ended_at' => $this->stream->ended_at?->toIso8601String(),
            'duration' => $this->stream->duration,
            'timestamp' => now()->toIso8601String(),
        ];

        // Include playback_url when transitioning to live so the
        // frontend can start the player without an extra API call.
        if ($this->stream->playback_url) {
            $data['playback_url'] = $this->stream->playback_url;
        }

        return $data;
    }
}
