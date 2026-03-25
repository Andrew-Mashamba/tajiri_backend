<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast stream status changes to ALL users on the Live tab.
 * Allows the Live grid to update when streams go live or end.
 *
 * Broadcasts on public 'live-streams' channel.
 */
class GlobalStreamStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public LiveStream $stream;
    public string $oldStatus;
    public string $newStatus;

    public function __construct(LiveStream $stream, string $oldStatus, string $newStatus)
    {
        $this->stream = $stream;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }

    /**
     * Broadcast on public channel for ALL users on the Live tab.
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
        return 'stream_status_changed';
    }

    /**
     * Data to broadcast - includes stream details for UI updates.
     */
    public function broadcastWith(): array
    {
        return [
            'stream_id' => $this->stream->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'stream' => [
                'id' => $this->stream->id,
                'title' => $this->stream->title,
                'description' => $this->stream->description,
                'thumbnail_path' => $this->stream->thumbnail_path,
                'category' => $this->stream->category,
                'status' => $this->stream->status,
                'viewers_count' => $this->stream->viewers_count ?? 0,
                'peak_viewers' => $this->stream->peak_viewers ?? 0,
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
