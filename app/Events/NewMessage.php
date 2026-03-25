<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'new_message';
    }

    public function broadcastWith(): array
    {
        $this->message->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'replyTo:id,conversation_id,sender_id,content,message_type',
            'replyTo.sender:id,first_name,last_name',
            'reactions',
        ]);

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'content' => $this->message->content,
            'message_type' => $this->message->message_type,
            'media_path' => $this->message->media_path,
            'media_type' => $this->message->media_type,
            'reply_to_id' => $this->message->reply_to_id,
            'forward_message_id' => $this->message->forward_message_id,
            'created_at' => $this->message->created_at->toIso8601String(),
            'sender' => $this->message->sender,
            'reply_to' => $this->message->replyTo,
            'reactions_grouped' => $this->message->reactions_grouped,
        ];
    }
}
