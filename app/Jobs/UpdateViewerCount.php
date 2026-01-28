<?php

namespace App\Jobs;

use App\Events\ViewerCountUpdated;
use App\Models\LiveStream;
use App\Models\StreamAnalytics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateViewerCount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $liveStreams = LiveStream::where('status', 'live')->get();

        foreach ($liveStreams as $stream) {
            $currentViewers = $stream->viewers()
                ->where('is_currently_watching', true)
                ->count();

            $updates = ['viewers_count' => $currentViewers];

            if ($currentViewers > $stream->peak_viewers) {
                $updates['peak_viewers'] = $currentViewers;
            }

            $stream->update($updates);

            // Store analytics snapshot
            StreamAnalytics::create([
                'stream_id' => $stream->id,
                'timestamp' => now(),
                'viewers_count' => $currentViewers,
                'engagement_rate' => $this->calculateEngagement($stream),
            ]);

            broadcast(new ViewerCountUpdated($stream));
        }
    }

    private function calculateEngagement(LiveStream $stream): float
    {
        if ($stream->total_viewers === 0) {
            return 0;
        }

        $engagements = $stream->likes_count + $stream->comments_count + $stream->gifts_count;
        return round(($engagements / $stream->total_viewers) * 100, 2);
    }
}
