<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendFomoPush extends Command
{
    protected $signature = "flywheel:send-fomo-push";
    protected $description = "Send FOMO push to inactive users with medium/full engagement";

    public function handle(): int
    {
        $this->info("Sending FOMO pushes...");

        $inactiveSince = now()->subHours(6);

        // Users inactive 6+ hours with medium or full engagement
        $users = DB::table("users as u")
            ->join("fcm_tokens as ft", "ft.user_id", "=", "u.id")
            ->join("user_engagement_levels as uel", "uel.user_id", "=", "u.id")
            ->whereIn("uel.engagement_level", ["medium", "full"])
            ->where(function ($q) use ($inactiveSince) {
                $q->whereNull("u.last_active_at")
                  ->orWhere("u.last_active_at", "<", $inactiveSince);
            })
            ->select("u.id as user_id")
            ->distinct()
            ->get();

        if ($users->isEmpty()) {
            $this->info("No inactive users to notify.");
            return 0;
        }

        // Find active gossip threads in last 6 hours
        $activeThread = DB::table("posts")
            ->where("created_at", ">=", $inactiveSince)
            ->orderByDesc("likes_count")
            ->first();

        if (!$activeThread) {
            $activeThread = DB::table("posts")
                ->orderByDesc("created_at")
                ->first();
        }

        if (!$activeThread) {
            $this->info("No active threads found.");
            return 0;
        }

        // Count active participants
        $activeCount = DB::table("comments")
            ->where("post_id", $activeThread->id)
            ->where("created_at", ">=", $inactiveSince)
            ->distinct("user_id")
            ->count("user_id");

        $activeCount = max($activeCount, 3);
        $topic = isset($activeThread->content)
            ? \Str::limit($activeThread->content, 40)
            : "a trending topic";

        $sent = 0;
        foreach ($users as $user) {
            // Check FOMO cap: skip users with 3+ FOMO notifications today
            $fomoCount = DB::table('notifications')
                ->where('notifiable_id', $user->user_id)
                ->where('data->type', 'fomo_trigger')
                ->whereDate('created_at', today())
                ->count();

            if ($fomoCount >= 3) {
                continue;
            }

            FcmNotificationService::sendToUser(
                $user->user_id,
                "fomo_push",
                ["post_id" => $activeThread->id, "active_count" => $activeCount],
                "You are missing out!",
                "{$activeCount} people are discussing \"{$topic}\" right now"
            );
            $sent++;
        }

        $this->info("FOMO push sent to {$sent} users.");
        return 0;
    }
}
