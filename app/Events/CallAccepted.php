<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallAccepted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $acceptedByUserId,
        public ?array $sdpAnswer = null
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('call.' . $this->callUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.accepted';
    }

    public function broadcastWith(): array
    {
        $data = [
            'call_id' => $this->callUuid,
            'accepted_by_user_id' => $this->acceptedByUserId,
            'accepted_at' => now()->toIso8601String(),
        ];

        if ($this->sdpAnswer) {
            $data['sdp_answer'] = $this->sdpAnswer;
        }

        return $data;
    }
}
