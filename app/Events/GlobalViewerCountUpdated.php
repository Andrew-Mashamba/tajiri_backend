<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast viewer count updates to ALL users on the Live tab.
 * This allows real-time viewer count updates without page refresh.
 *
 * Broadcasts on public 'live-streams' channel so all users receive updates.
 */
class GlobalViewerCountUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $streamId;
    public int $viewersCount;
    public int $peakViewers;
    public int $uniqueViewers;

    public function __construct(int $streamId, int $viewersCount, int $peakViewers, int $uniqueViewers = 0)
    {
        $this->streamId = $streamId;
        $this->viewersCount = $viewersCount;
        $this->peakViewers = $peakViewers;
        $this->uniqueViewers = $uniqueViewers;
    }

    /**
     * Get the channels the event should broadcast on.
     * Using a public channel so ALL users on the Live tab receive updates.
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
        return 'viewer_count_updated';
    }

    /**
     * Data to broadcast to all connected clients.
     */
    public function broadcastWith(): array
    {
        return [
            'stream_id' => $this->streamId,
            'viewers_count' => $this->viewersCount,
            'peak_viewers' => $this->peakViewers,
            'unique_viewers' => $this->uniqueViewers,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
