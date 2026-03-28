<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendFollowerMilestonePush extends Command
{
    protected $signature = "flywheel:send-milestone-push";
    protected $description = "Send push notifications when creators hit follower milestones";

    protected array $milestones = [100, 500, 1000, 5000, 10000, 50000, 100000];

    public function handle(): int
    {
        $this->info("Checking follower milestones...");

        $sent = 0;

        foreach ($this->milestones as $milestone) {
            // Find users whose followers_count is exactly at this milestone
            // (updated in the last 2 hours to avoid re-notifying)
            $creators = DB::table("user_profiles as up")
                ->where("up.followers_count", $milestone)
                ->where("up.updated_at", ">=", now()->subHours(2))
                ->leftJoin(
                    DB::raw("(SELECT notifiable_id FROM notifications WHERE data->>'$.type' = 'follower_milestone' AND data->>'$.milestone' = '{$milestone}' GROUP BY notifiable_id) as already_notified"),
                    "already_notified.notifiable_id", "=", "up.id"
                )
                ->whereNull("already_notified.notifiable_id")
                ->select("up.id as user_id", "up.followers_count")
                ->get();

            foreach ($creators as $creator) {
                // Send to the creator
                FcmNotificationService::sendToUser(
                    $creator->user_id,
                    "follower_milestone",
                    ["milestone" => $milestone, "type" => "follower_milestone"],
                    "Congratulations!",
                    "You've reached {$milestone} followers!"
                );

                // Get followers to notify
                $followerIds = DB::table("follows")
                    ->where("following_id", $creator->user_id)
                    ->pluck("follower_id")
                    ->toArray();

                if (!empty($followerIds)) {
                    // Get creator name
                    $creatorUser = DB::table("users")->where("id", $creator->user_id)->first();
                    $creatorName = $creatorUser ? ($creatorUser->name ?? "Someone") : "Someone";

                    FcmNotificationService::sendToUsers(
                        $followerIds,
                        "creator_milestone",
                        ["creator_id" => $creator->user_id, "milestone" => $milestone, "type" => "follower_milestone"],
                        "{$creatorName} hit a milestone!",
                        "{$creatorName} just hit {$milestone} followers! Check out their content"
                    );
                }

                $sent++;
            }
        }

        $this->info("Milestone notifications sent for {$sent} creators.");
        return 0;
    }
}
