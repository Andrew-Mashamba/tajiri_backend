<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallIncoming implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callUuid,
        public int $callerId,
        public string $callerName,
        public string $callerAvatarUrl,
        public string $callType,
        public int $calleeId,
        public bool $ongoing = false
    ) {}

    /**
     * Broadcast on both the call channel and the callee's personal channel.
     * The call channel ensures subscribers get it; the user channel ensures
     * the callee gets it even before subscribing to the call channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('call.' . $this->callUuid),
            new PrivateChannel('user.' . $this->calleeId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.incoming';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callUuid,
            'caller_id' => $this->callerId,
            'caller_name' => $this->callerName,
            'caller_avatar_url' => $this->callerAvatarUrl,
            'type' => $this->callType,
            'ongoing' => $this->ongoing,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Create from a Call model for convenience.
     */
    public static function fromCall(Call $call): self
    {
        $call->load('caller:id,first_name,last_name,username,profile_photo_path');
        $caller = $call->caller;
        $callerName = $caller ? trim($caller->first_name . ' ' . $caller->last_name) : '';
        $callerAvatarUrl = $caller?->profile_photo_path
            ? asset('storage/' . $caller->profile_photo_path)
            : '';

        return new self(
            $call->call_id,
            $call->caller_id,
            $callerName,
            $callerAvatarUrl,
            $call->type,
            $call->callee_id,
            false // 1:1 incoming, not ongoing group-add
        );
    }
}
