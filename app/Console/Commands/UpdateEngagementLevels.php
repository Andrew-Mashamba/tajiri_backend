<?php

namespace App\Console\Commands;

use App\Models\UserEngagementLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UpdateEngagementLevels extends Command
{
    protected $signature = 'flywheel:update-engagement-levels';
    protected $description = 'Recalculate user engagement levels based on weekly activity and behavioral signals';

    public function handle(): int
    {
        $now = Carbon::now();
        $since14 = $now->copy()->subDays(14);

        // Aggregate behavioral signals per user over last 14 days
        $signals = DB::table('user_events')
            ->select(
                'user_id',
                DB::raw('COUNT(*) as action_count'),
                DB::raw('COUNT(DISTINCT DATE(timestamp)) as days_active'),
                DB::raw('COUNT(DISTINCT session_id) as session_count'),
                DB::raw('MIN(timestamp) as first_action_at')
            )
            ->where('created_at', '>=', $since14)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Posts created in last 14 days per user
        $postsCreated = DB::table('posts')
            ->select('user_id', DB::raw('COUNT(*) as posts_count'))
            ->where('created_at', '>=', $since14)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $updated = 0;

        foreach ($signals as $userId => $row) {
            $firstActionAt = Carbon::parse($row->first_action_at);
            $daysSinceFirst = $firstActionAt->diffInDays($now);

            // sessions_per_week: 14-day window divided by 2
            $sessionsPerWeek = $row->session_count / 2;
            $posts = isset($postsCreated[$userId]) ? $postsCreated[$userId]->posts_count : 0;

            // Determine engagement level based on behavioral thresholds
            if ($daysSinceFirst >= 49 && $sessionsPerWeek >= 5 && $row->action_count >= 30) {
                $level = 'full';
            } elseif ($daysSinceFirst >= 14 && $sessionsPerWeek >= 3 && $row->action_count >= 10) {
                $level = 'medium';
            } else {
                $level = 'gentle';
            }

            $oldLevel = UserEngagementLevel::where('user_id', $userId)->value('level');

            UserEngagementLevel::updateOrCreate(
                ['user_id' => $userId],
                [
                    'level' => $level,
                    'weekly_actions' => $row->action_count,
                    'days_active_14d' => $row->days_active,
                    'sessions_14d' => $row->session_count,
                    'posts_created_14d' => $posts,
                ]
            );

            if ($oldLevel !== null && $oldLevel !== $level) {
                \App\Services\FcmNotificationService::sendToUser(
                    $userId,
                    'milestone',
                    ['milestone' => "You are now a {$level} user!"],
                    'Level Up!',
                    "Your engagement level is now: {$level}"
                );
            }
            $updated++;
        }

        $this->info("Updated engagement levels for {$updated} users.");
        return self::SUCCESS;
    }
}
