<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendWeeklyReportPush extends Command
{
    protected $signature = "flywheel:send-weekly-report-push";
    protected $description = "Send weekly report push to creators every Monday";

    public function handle(): int
    {
        $this->info("Sending weekly report pushes to creators...");

        $weekStart = now()->subWeek()->startOfDay();

        // Creators who have posts and FCM tokens
        $creators = DB::table("users as u")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "u.id")
            ->join("posts as p", "p.user_id", "=", "u.id")
            ->select(
                "u.id as user_id",
                "u.name",
                DB::raw("COUNT(DISTINCT p.id) as total_posts"),
                DB::raw("SUM(CASE WHEN p.created_at >= ? THEN 1 ELSE 0 END) as week_posts"),
                DB::raw("SUM(CASE WHEN p.created_at >= ? THEN p.likes_count ELSE 0 END) as week_likes"),
                DB::raw("SUM(CASE WHEN p.created_at >= ? THEN p.views_count ELSE 0 END) as week_views")
            )
            ->distinct("u.id")
            ->groupBy("u.id", "u.name")
            ->having("total_posts", ">", 0)
            ->get([$weekStart, $weekStart, $weekStart]);

        $sent = 0;
        foreach ($creators as $creator) {
            $weekPosts = (int) $creator->week_posts;
            $weekLikes = (int) $creator->week_likes;
            $weekViews = (int) $creator->week_views;

            $body = "Check your performance summary inside the app";
            if ($weekPosts > 0) {
                $body = "{$weekPosts} posts, {$weekLikes} likes, {$weekViews} views this week";
            }

            FcmNotificationService::sendToUser(
                $creator->user_id,
                "weekly_report",
                [
                    "week_posts" => $weekPosts,
                    "week_likes" => $weekLikes,
                    "week_views" => $weekViews,
                ],
                "Your weekly report is ready",
                $body
            );
            $sent++;
        }

        $this->info("Weekly report sent to {$sent} creators.");
        return 0;
    }
}
