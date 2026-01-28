<?php

namespace App\Jobs;

use App\Events\StreamEnded;
use App\Models\LiveStream;
use App\Models\StreamAnalytics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TransitionToEnded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $streams = LiveStream::where('status', 'ending')
            ->where('updated_at', '<', now()->subSeconds(5))
            ->get();

        foreach ($streams as $stream) {
            $duration = $stream->started_at
                ? now()->diffInSeconds($stream->started_at)
                : 0;

            $uniqueViewers = $stream->viewers()->distinct('user_id')->count('user_id');

            $stream->update([
                'status' => 'ended',
                'ended_at' => now(),
                'duration' => $duration,
                'unique_viewers' => $uniqueViewers,
            ]);

            // Mark all remaining viewers as left
            $stream->viewers()
                ->whereNull('left_at')
                ->update([
                    'left_at' => now(),
                    'is_currently_watching' => false,
                ]);

            // Generate final analytics snapshot
            GenerateStreamAnalytics::dispatch($stream);

            broadcast(new StreamEnded($stream));
        }
    }
}
