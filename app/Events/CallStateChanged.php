<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public string $event
    ) {}

    public function broadcastOn(): array
    {
        // Broadcast to both caller and callee private channels
        return [
            new PrivateChannel('user.' . $this->call->caller_id),
            new PrivateChannel('user.' . $this->call->callee_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call_state_changed';
    }

    public function broadcastWith(): array
    {
        $this->call->load(['caller:id,first_name,last_name,username,profile_photo_path', 'callee:id,first_name,last_name,username,profile_photo_path']);

        return [
            'call_id' => $this->call->id,
            'call_uuid' => $this->call->call_id,
            'event' => $this->event,
            'status' => $this->call->status,
            'type' => $this->call->type,
            'caller_id' => $this->call->caller_id,
            'callee_id' => $this->call->callee_id,
            'caller' => $this->call->caller,
            'callee' => $this->call->callee,
            'answered_at' => $this->call->answered_at?->toIso8601String(),
            'ended_at' => $this->call->ended_at?->toIso8601String(),
            'duration' => $this->call->duration,
            'end_reason' => $this->call->end_reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
