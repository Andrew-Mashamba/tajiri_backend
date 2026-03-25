<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallRejected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $rejectedByUserId
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.rejected';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'rejected_by_user_id' => $this->rejectedByUserId,
            'rejected_at' => now()->toIso8601String(),
        ];
    }
}
