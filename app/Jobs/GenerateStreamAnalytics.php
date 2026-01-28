<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\StreamAnalytics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateStreamAnalytics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public LiveStream $stream
    ) {}

    public function handle(): void
    {
        $stream = $this->stream;

        $uniqueViewers = $stream->viewers()->distinct('user_id')->count('user_id');
        $avgWatchTime = $stream->viewers()->avg('watch_duration') ?? 0;

        $stream->update([
            'unique_viewers' => $uniqueViewers,
        ]);

        // Final analytics snapshot
        StreamAnalytics::create([
            'stream_id' => $stream->id,
            'timestamp' => now(),
            'viewers_count' => 0,
            'engagement_rate' => $stream->total_viewers > 0
                ? round((($stream->likes_count + $stream->comments_count + $stream->gifts_count) / $stream->total_viewers) * 100, 2)
                : 0,
            'data' => [
                'type' => 'final',
                'total_viewers' => $stream->total_viewers,
                'unique_viewers' => $uniqueViewers,
                'peak_viewers' => $stream->peak_viewers,
                'average_watch_time' => round($avgWatchTime),
                'total_likes' => $stream->likes_count,
                'total_comments' => $stream->comments_count,
                'total_shares' => $stream->shares_count,
                'total_gifts' => $stream->gifts_count,
                'total_revenue' => (float) $stream->gifts_value,
                'duration' => $stream->duration,
            ],
        ]);
    }
}
