<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BuildInterestProfiles extends Command
{
    protected $signature = 'flywheel:build-interest-profiles {--user= : Rebuild for a specific user ID only}';
    protected $description = 'Aggregate user_events into user_interest_profiles';

    public function handle(): int
    {
        $since = Carbon::now()->subDays(30);

        // If --user flag provided, only rebuild for that user
        $specificUserId = $this->option('user');
        if ($specificUserId) {
            $userIds = collect([(int) $specificUserId]);
        } else {
            $userIds = DB::table('user_events')
                ->where('created_at', '>=', $since)
                ->distinct()
                ->pluck('user_id');
        }

        $updated = 0;

        foreach ($userIds as $userId) {
            $creatorWeights = DB::table('user_events')
                ->select('creator_id', DB::raw("SUM(CASE WHEN event_type = 'view' THEN 1 WHEN event_type = 'like' THEN 3 WHEN event_type = 'share' THEN 5 WHEN event_type = 'comment' THEN 4 ELSE 1 END) as weight"))
                ->where('user_id', $userId)
                ->where('created_at', '>=', $since)
                ->whereNotNull('creator_id')
                ->groupBy('creator_id')
                ->orderByDesc('weight')
                ->limit(50)
                ->get();

            $creatorAffinities = $creatorWeights->pluck('weight', 'creator_id')->toArray();

            $hourly = DB::table('user_events')
                ->select(DB::raw("EXTRACT(HOUR FROM timestamp) as hour"), DB::raw('COUNT(*) as cnt'))
                ->where('user_id', $userId)
                ->where('created_at', '>=', $since)
                ->groupBy(DB::raw("EXTRACT(HOUR FROM timestamp)"))
                ->pluck('cnt', 'hour')
                ->toArray();

            $totalEvents = DB::table('user_events')->where('user_id', $userId)->where('created_at', '>=', $since)->count();
            $threadEvents = DB::table('user_events')
                ->join('gossip_thread_posts', 'gossip_thread_posts.post_id', '=', 'user_events.post_id')
                ->where('user_events.user_id', $userId)
                ->where('user_events.created_at', '>=', $since)
                ->count();
            $gossipAffinity = $totalEvents > 0 ? round($threadEvents / $totalEvents, 3) : 0;

            DB::table('user_interest_profiles')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'topic_weights' => json_encode([]),
                    'creator_affinities' => json_encode($creatorAffinities),
                    'format_preferences' => json_encode([]),
                    'activity_patterns' => json_encode($hourly),
                    'gossip_affinity' => $gossipAffinity,
                    'commerce_signals' => json_encode([]),
                    'computed_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $updated++;
        }

        $this->info("Built interest profiles for {$updated} users.");
        return self::SUCCESS;
    }
}
