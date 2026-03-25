<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallReaction implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $userId,
        public string $userName,
        public string $emoji
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.reaction';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'from_user_id' => $this->userId,
            'from_user_name' => $this->userName,
            'emoji' => $this->emoji,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
