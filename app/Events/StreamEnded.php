<?php

namespace App\Events;

use App\Models\LiveStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamEnded implements ShouldBroadcast
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
            'status' => 'ended',
            'duration' => $this->stream->duration,
            'total_viewers' => $this->stream->total_viewers,
            'peak_viewers' => $this->stream->peak_viewers,
        ];
    }
}
