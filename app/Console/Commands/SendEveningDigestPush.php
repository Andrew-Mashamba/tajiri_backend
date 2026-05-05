<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendEveningDigestPush extends Command
{
    protected $signature = "flywheel:send-evening-digest";
    protected $description = "Send evening digest push notifications to active users";

    public function handle(): int
    {
        $this->info("Sending evening digest pushes...");

        // Get users with FCM tokens who have medium or full engagement
        $users = DB::table("users as u")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "u.id")
            ->join("user_engagement_levels as uel", "uel.user_id", "=", "u.id")
            ->whereIn("uel.engagement_level", ["medium", "full"])
            ->select("u.id as user_id", "u.name")
            ->distinct()
            ->get();

        if ($users->isEmpty()) {
            $this->info("No users to notify.");
            return 0;
        }

        // Count threads created today
        $threadCount = DB::table("posts")
            ->whereDate("created_at", today())
            ->count();

        $sent = 0;
        foreach ($users as $user) {
            FcmNotificationService::sendToUser(
                $user->user_id,
                "evening_digest",
                ["thread_count" => $threadCount],
                "Usiku Mwema!",
                "{$threadCount} new threads appeared today. See your evening recap."
            );
            $sent++;
        }

        $this->info("Evening digest sent to {$sent} users.");
        return 0;
    }
}
