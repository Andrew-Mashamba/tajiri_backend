<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TrackViralAssists extends Command
{
    protected $signature = "flywheel:track-viral-assists";
    protected $description = "Track viral assists: posts that went viral after being shared";

    public function handle(): int
    {
        $this->info("Tracking viral assists...");

        $window = now()->subHours(24);
        $notified = 0;

        // Find post shares from the last 24 hours
        $shares = DB::table("post_shares as ps")
            ->join("posts as p", "p.id", "=", "ps.post_id")
            ->where("ps.created_at", ">=", $window)
            ->select(
                "ps.user_id as sharer_user_id",
                "ps.post_id",
                "p.user_id as creator_id",
                "p.likes_count as current_likes"
            )
            ->get();

        foreach ($shares as $share) {
            // Check if we already have a viral_assists record
            $existing = DB::table("viral_assists")
                ->where("sharer_user_id", $share->sharer_user_id)
                ->where("post_id", $share->post_id)
                ->first();

            if ($existing) {
                // Already detected; check if not yet notified and post went viral
                if (!$existing->notified && $share->current_likes >= $existing->likes_at_share * 2 && $share->current_likes > 10) {
                    // Update record and send notification
                    DB::table("viral_assists")
                        ->where("id", $existing->id)
                        ->update([
                            "likes_at_detection" => $share->current_likes,
                            "notified" => true,
                            "updated_at" => now(),
                        ]);

                    FcmNotificationService::sendToUser(
                        $share->sharer_user_id,
                        "viral_assist",
                        ["post_id" => $share->post_id, "type" => "viral_assist"],
                        "Your share is making waves!",
                        "A post you shared is trending! Your share helped it go viral"
                    );

                    $notified++;
                }
                continue;
            }

            // New share — record current likes as baseline
            DB::table("viral_assists")->insertOrIgnore([
                "sharer_user_id" => $share->sharer_user_id,
                "post_id" => $share->post_id,
                "creator_id" => $share->creator_id,
                "likes_at_share" => $share->current_likes,
                "likes_at_detection" => $share->current_likes,
                "notified" => false,
                "created_at" => now(),
                "updated_at" => now(),
            ]);
        }

        $this->info("Viral assists tracked. {$notified} notifications sent.");
        return 0;
    }
}
