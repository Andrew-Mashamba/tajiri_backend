<?php

namespace App\Console\Commands;

use App\Services\FcmNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendViralAssistPush extends Command
{
    protected $signature = "flywheel:send-viral-assist";
    protected $description = "Send viral assist push to creators whose posts are gaining rapid likes";

    public function handle(): int
    {
        $this->info("Checking for viral post velocity spikes...");

        $now = now();
        $thirtyMinAgo = $now->copy()->subMinutes(30);
        $sixtyMinAgo = $now->copy()->subMinutes(60);

        // Find posts that gained 50%+ likes in the last 30 min vs prior 30 min
        // We use post_likes table if available, otherwise approximate via posts table
        $viralPosts = collect();

        if (DB::getSchemaBuilder()->hasTable("post_likes")) {
            $viralPosts = DB::table("posts as p")
                ->join("fcm_tokens as ft", "ft.user_id", "=", "p.user_id")
                ->select(
                    "p.id as post_id",
                    "p.user_id",
                    DB::raw("COUNT(CASE WHEN pl_recent.id IS NOT NULL THEN 1 END) as recent_likes"),
                    DB::raw("COUNT(CASE WHEN pl_prior.id IS NOT NULL THEN 1 END) as prior_likes")
                )
                ->leftJoin("post_likes as pl_recent", function ($join) use ($thirtyMinAgo, $now) {
                    $join->on("pl_recent.post_id", "=", "p.id")
                         ->where("pl_recent.created_at", ">=", $thirtyMinAgo)
                         ->where("pl_recent.created_at", "<=", $now);
                })
                ->leftJoin("post_likes as pl_prior", function ($join) use ($sixtyMinAgo, $thirtyMinAgo) {
                    $join->on("pl_prior.post_id", "=", "p.id")
                         ->where("pl_prior.created_at", ">=", $sixtyMinAgo)
                         ->where("pl_prior.created_at", "<", $thirtyMinAgo);
                })
                ->groupBy("p.id", "p.user_id")
                ->havingRaw("recent_likes >= 5 AND (prior_likes = 0 OR recent_likes >= prior_likes * 1.5)")
                ->distinct("p.id")
                ->get();
        } else {
            // Fallback: find recently popular posts with high likes
            $viralPosts = DB::table("posts as p")
                ->join("fcm_tokens as ft", "ft.user_id", "=", "p.user_id")
                ->where("p.updated_at", ">=", $thirtyMinAgo)
                ->where("p.likes_count", ">=", 20)
                ->select("p.id as post_id", "p.user_id", DB::raw("p.likes_count as recent_likes"), DB::raw("0 as prior_likes"))
                ->distinct("p.id")
                ->limit(50)
                ->get();
        }

        if ($viralPosts->isEmpty()) {
            $this->info("No viral velocity spikes detected.");
            return 0;
        }

        $sent = 0;
        $notified = [];

        foreach ($viralPosts as $post) {
            // Only notify each user once per run
            if (in_array($post->user_id, $notified)) {
                continue;
            }
            $notified[] = $post->user_id;

            $recentLikes = (int) $post->recent_likes;

            FcmNotificationService::sendToUser(
                $post->user_id,
                "viral_assist",
                ["post_id" => $post->post_id, "recent_likes" => $recentLikes],
                "Your post is going viral!",
                "You gained {$recentLikes} likes in 30 minutes. Reply to comments to boost it further!"
            );
            $sent++;
        }

        $this->info("Viral assist push sent to {$sent} creators.");
        return 0;
    }
}
