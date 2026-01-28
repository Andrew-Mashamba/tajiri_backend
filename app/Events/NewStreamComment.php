<?php

namespace App\Events;

use App\Models\StreamComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewStreamComment implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StreamComment $comment
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('stream.' . $this->comment->stream_id),
        ];
    }

    public function broadcastWith(): array
    {
        $this->comment->load('user:id,first_name,last_name,username,profile_photo_path');

        return [
            'id' => $this->comment->id,
            'stream_id' => $this->comment->stream_id,
            'user' => $this->comment->user,
            'message' => $this->comment->content,
            'created_at' => $this->comment->created_at->toIso8601String(),
        ];
    }
}
