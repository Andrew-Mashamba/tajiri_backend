<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CreatorScore;
use App\Models\CreatorScoreHistory;
use Illuminate\Support\Facades\DB;

class CalculateCreatorScores extends Command
{
    protected $signature = "flywheel:calculate-creator-scores";
    protected $description = "Weekly recalculation of creator scores and tiers";

    public function handle(): int
    {
        $thirtyDaysAgo = now()->subDays(30);
        $today = now()->toDateString();

        // Creators who posted in last 30 days
        $creators = DB::table("posts")
            ->where("created_at", ">=", $thirtyDaysAgo)
            ->select("user_id")
            ->distinct()
            ->pluck("user_id");

        // Platform average engagement rate
        $platformAvg = DB::table("posts")
            ->where("created_at", ">=", $thirtyDaysAgo)
            ->where("impressions_count", ">", 0)
            ->selectRaw("AVG((likes_count + comments_count + shares_count + saves_count) * 1.0 / impressions_count) as avg_rate")
            ->value("avg_rate") ?? 0.01;

        $tiers = ["rising" => 0, "established" => 0, "star" => 0, "legend" => 0];

        foreach ($creators as $userId) {
            // Community score: reply rate to comments
            $totalComments = DB::table("comments")
                ->join("posts", "comments.post_id", "=", "posts.id")
                ->where("posts.user_id", $userId)
                ->where("comments.created_at", ">=", $thirtyDaysAgo)
                ->where("comments.user_id", "!=", $userId)
                ->count();
            $replies = $totalComments > 0 ? DB::table("comments")
                ->join("posts", "comments.post_id", "=", "posts.id")
                ->where("posts.user_id", $userId)
                ->where("comments.user_id", $userId)
                ->where("comments.created_at", ">=", $thirtyDaysAgo)
                ->count() : 0;
            $replyRate = $totalComments > 0 ? min($replies / $totalComments, 1.0) : 0;
            $communityScore = min($replyRate / 0.5 * 100, 100);

            // Quality score: engagement rate vs platform avg
            $creatorRate = DB::table("posts")
                ->where("user_id", $userId)
                ->where("created_at", ">=", $thirtyDaysAgo)
                ->where("impressions_count", ">", 0)
                ->selectRaw("AVG((likes_count + comments_count + shares_count + saves_count) * 1.0 / impressions_count) as avg_rate")
                ->value("avg_rate") ?? 0;
            $qualityScore = $platformAvg > 0 ? min(($creatorRate / ($platformAvg * 2)) * 100, 100) : 0;

            // Consistency score: posting frequency (1 per 48h = 15 posts/30 days = 100)
            $postCount = DB::table("posts")
                ->where("user_id", $userId)
                ->where("created_at", ">=", $thirtyDaysAgo)
                ->count();
            $consistencyScore = min(($postCount / 15) * 100, 100);

            // Final score
            $score = ($communityScore * 0.3) + ($qualityScore * 0.4) + ($consistencyScore * 0.3);
            $tier = match(true) {
                $score >= 85 => "legend",
                $score >= 60 => "star",
                $score >= 30 => "established",
                default => "rising",
            };
            $multiplier = match($tier) {
                "legend" => 2.5, "star" => 2.0, "established" => 1.5, default => 1.0,
            };

            CreatorScore::updateOrCreate(["user_id" => $userId], [
                "score" => round($score, 2),
                "tier" => $tier,
                "community_score" => round($communityScore, 2),
                "quality_score" => round($qualityScore, 2),
                "consistency_score" => round($consistencyScore, 2),
                "tier_multiplier" => $multiplier,
                "computed_at" => now(),
            ]);

            CreatorScoreHistory::create([
                "user_id" => $userId,
                "score" => round($score, 2),
                "tier" => $tier,
                "snapshot_date" => $today,
                "component_scores" => [
                    "community" => round($communityScore, 2),
                    "quality" => round($qualityScore, 2),
                    "consistency" => round($consistencyScore, 2),
                ],
                "created_at" => now(),
            ]);

            $tiers[$tier]++;
        }

        $total = count($creators);
        $this->info("Scored {$total} creators. Tiers: " . json_encode($tiers));
        return 0;
    }
}
