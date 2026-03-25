<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantLeft implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $userId
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.participant_left';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'user_id' => $this->userId,
            'left_at' => now()->toIso8601String(),
        ];
    }
}
