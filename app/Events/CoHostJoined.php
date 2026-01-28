<?php

namespace App\Events;

use App\Models\StreamCohost;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CoHostJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StreamCohost $cohost
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('stream.' . $this->cohost->stream_id),
        ];
    }

    public function broadcastWith(): array
    {
        $this->cohost->load('user:id,first_name,last_name,username,profile_photo_path');

        return [
            'stream_id' => $this->cohost->stream_id,
            'co_host' => $this->cohost->user,
        ];
    }
}
