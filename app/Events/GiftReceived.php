<?php

namespace App\Events;

use App\Models\StreamGift;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GiftReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StreamGift $streamGift
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('stream.' . $this->streamGift->stream_id),
        ];
    }

    public function broadcastWith(): array
    {
        $this->streamGift->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'gift',
        ]);

        return [
            'stream_id' => $this->streamGift->stream_id,
            'sender' => $this->streamGift->sender,
            'gift' => [
                'id' => $this->streamGift->gift->id,
                'name' => $this->streamGift->gift->name,
                'icon' => $this->streamGift->gift->icon_path,
                'animation' => $this->streamGift->gift->animation_path,
            ],
            'quantity' => $this->streamGift->quantity,
            'total_value' => $this->streamGift->total_value,
        ];
    }
}
