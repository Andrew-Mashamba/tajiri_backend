<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalingIceCandidate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $fromUserId,
        public array $candidate
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'signaling.ice_candidate';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'from_user_id' => $this->fromUserId,
            'candidate' => $this->candidate,
        ];
    }
}
