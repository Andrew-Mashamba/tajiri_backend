<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendStreakWarningPush extends Command
{
    protected $signature = "flywheel:send-streak-warning";
    protected $description = "Warn users with active streaks who have not opened the app today";

    public function handle(): int
    {
        $this->info("Sending streak warning pushes...");

        $todayStart = now()->startOfDay();
        $sent = 0;

        // Viewer streaks
        $viewerStreaks = DB::table("viewer_streaks as vs")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "vs.user_id")
            ->where("vs.current_streak", ">", 0)
            ->where(function ($q) use ($todayStart) {
                $q->whereNull("vs.last_activity_date")
                  ->orWhere("vs.last_activity_date", "<", $todayStart);
            })
            ->select("vs.user_id", "vs.current_streak")
            ->distinct()
            ->get();

        foreach ($viewerStreaks as $streak) {
            FcmNotificationService::sendToUser(
                $streak->user_id,
                "streak_warning",
                ["streak_days" => $streak->current_streak, "streak_type" => "viewer"],
                "Streak at risk!",
                "Your {$streak->current_streak}-day viewing streak expires tonight. Open the app now!"
            );
            $sent++;
        }

        // Creator streaks
        $creatorStreaks = DB::table("creator_streaks as cs")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "cs.user_id")
            ->where("cs.current_streak", ">", 0)
            ->where(function ($q) use ($todayStart) {
                $q->whereNull("cs.last_post_date")
                  ->orWhere("cs.last_post_date", "<", $todayStart);
            })
            ->select("cs.user_id", "cs.current_streak")
            ->distinct()
            ->get();

        foreach ($creatorStreaks as $streak) {
            FcmNotificationService::sendToUser(
                $streak->user_id,
                "streak_warning",
                ["streak_days" => $streak->current_streak, "streak_type" => "creator"],
                "Post today to keep your streak!",
                "Your {$streak->current_streak}-day creator streak will break if you do not post today"
            );
            $sent++;
        }

        $this->info("Streak warning sent to {$sent} users.");
        return 0;
    }
}
