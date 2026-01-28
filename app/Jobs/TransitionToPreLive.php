<?php

namespace App\Jobs;

use App\Events\StreamStatusChanged;
use App\Models\LiveStream;
use App\Models\StreamNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TransitionToPreLive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $streams = LiveStream::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->addMinutes(30))
            ->where('scheduled_at', '>', now())
            ->get();

        foreach ($streams as $stream) {
            $stream->update([
                'status' => 'pre_live',
                'pre_live_started_at' => now(),
            ]);

            broadcast(new StreamStatusChanged($stream));

            // Notify subscribers
            $this->notifyFollowers($stream, 'starting_soon');
        }
    }

    private function notifyFollowers(LiveStream $stream, string $type): void
    {
        $subscriberIds = \DB::table('stream_subscriptions')
            ->where('streamer_id', $stream->user_id)
            ->where('notify_live', true)
            ->pluck('subscriber_id');

        $notifications = $subscriberIds->map(fn ($userId) => [
            'stream_id' => $stream->id,
            'user_id' => $userId,
            'type' => $type,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        if (!empty($notifications)) {
            StreamNotification::insert($notifications);
        }
    }
}
