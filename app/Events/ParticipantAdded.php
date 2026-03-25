<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $userId,
        public string $userName,
        public string $userAvatarUrl,
        public int $addedByUserId
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.participant_added';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'user_avatar_url' => $this->userAvatarUrl,
            'added_by_user_id' => $this->addedByUserId,
            'added_at' => now()->toIso8601String(),
        ];
    }
}
