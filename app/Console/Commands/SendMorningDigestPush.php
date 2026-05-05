<?php

namespace App\Console\Commands;

use App\Models\FcmToken;
use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendMorningDigestPush extends Command
{
    protected $signature = "flywheel:send-morning-digest";
    protected $description = "Send morning digest push notifications to active users";

    public function handle(): int
    {
        $this->info("Sending morning digest pushes...");

        // Get users with FCM tokens who are not on gentle engagement
        $users = DB::table("users as u")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "u.id")
            ->leftJoin("user_engagement_levels as uel", "uel.user_id", "=", "u.id")
            ->where(function ($q) {
                $q->whereNull("uel.engagement_level")
                  ->orWhere("uel.engagement_level", "!=", "gentle");
            })
            ->select("u.id as user_id", "u.name")
            ->distinct()
            ->get();

        if ($users->isEmpty()) {
            $this->info("No users to notify.");
            return 0;
        }

        // Count overnight activity (last 8 hours)
        $since = now()->subHours(8);

        $threadCount = DB::table("posts")
            ->where("created_at", ">=", $since)
            ->count();

        $trendingCount = DB::table("posts")
            ->where("created_at", ">=", $since)
            ->where("likes_count", ">", 10)
            ->count();

        $sent = 0;
        foreach ($users as $user) {
            $body = "See what happened overnight";
            if ($threadCount > 0) {
                $body = "{$threadCount} new posts" . ($trendingCount > 0 ? ", {$trendingCount} trending" : "") . " since you were last here";
            }

            FcmNotificationService::sendToUser(
                $user->user_id,
                "morning_digest",
                ["thread_count" => $threadCount, "trending_count" => $trendingCount],
                "Good morning! Your digest is ready",
                $body
            );
            $sent++;
        }

        $this->info("Morning digest sent to {$sent} users.");
        return 0;
    }
}
