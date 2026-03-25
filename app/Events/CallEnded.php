<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $endedByUserId,
        public string $reason = 'ended_by_user'
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'ended_by_user_id' => $this->endedByUserId,
            'ended_at' => now()->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
