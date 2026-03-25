<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public string $updateType = 'edited'
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message_updated';
    }

    public function broadcastWith(): array
    {
        $this->message->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'reactions',
        ]);

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'update_type' => $this->updateType,
            'content' => $this->message->content,
            'edited_at' => $this->message->edited_at?->toIso8601String(),
            'reactions_grouped' => $this->message->reactions_grouped,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
