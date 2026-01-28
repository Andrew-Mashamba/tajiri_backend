<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ViewerCountUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveStream $stream
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('stream.' . $this->stream->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'stream_id' => $this->stream->id,
            'current_viewers' => $this->stream->viewers_count,
            'peak_viewers' => $this->stream->peak_viewers,
        ];
    }
}
