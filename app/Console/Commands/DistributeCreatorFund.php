<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CreatorFundPool;
use App\Models\CreatorFundPayout;
use App\Models\CreatorScore;
use App\Models\CreatorStreak;
use App\Models\Post;
use App\Models\GossipThreadPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DistributeCreatorFund extends Command
{
    protected $signature = "fund:distribute";
    protected $description = "Distribute monthly creator fund pool to qualifying creators";

    public function handle(): int
    {
        $month = Carbon::now()->subMonth()->format("Y-m");
        $pool = CreatorFundPool::firstOrCreate(
            ["month" => $month],
            ["total_amount" => 10000000, "currency" => "TZS"]
        );

        if ($pool->is_distributed) {
            $this->info("Pool for {$month} already distributed.");
            return Command::SUCCESS;
        }

        // Get qualifying creators
        $monthStart = Carbon::parse($month . "-01")->startOfMonth();
        $monthEnd = Carbon::parse($month . "-01")->endOfMonth();

        $creators = Post::select("user_id")
            ->whereBetween("created_at", [$monthStart, $monthEnd])
            ->whereIn("status", ["published", "active"])
            ->groupBy("user_id")
            ->havingRaw("COUNT(*) >= ?", [$pool->min_posts])
            ->pluck("user_id");

        if ($creators->isEmpty()) {
            $this->info("No qualifying creators for {$month}.");
            return Command::SUCCESS;
        }

        $payouts = [];
        $totalFinalScore = 0;

        foreach ($creators as $userId) {
            $posts = Post::where("user_id", $userId)
                ->whereBetween("created_at", [$monthStart, $monthEnd])
                ->get();

            $baseScore = $posts->sum(function ($p) {
                return ($p->views_count ?? 0) * 0.01
                    + ($p->likes_count ?? 0) * 0.1
                    + ($p->shares_count ?? 0) * 0.3
                    + ($p->saves_count ?? 0) * 0.2
                    + ($p->comments_count ?? 0) * 0.15;
            });

            $score = CreatorScore::where("user_id", $userId)->first();
            $streak = CreatorStreak::where("user_id", $userId)->first();

            $tierMult = $score ? (float) $score->tier_multiplier : 1.0;
            $streakMult = $streak ? (float) $streak->streak_multiplier : 1.0;
            $communityMult = 1.0; // TODO: calculate from reply rates
            $viralityMult = 1.0;

            // Check if any posts triggered gossip threads
            $postIds = $posts->pluck("id")->toArray();
            $threadCount = DB::table("gossip_threads")->whereIn("seed_post_id", $postIds)->count();
            if ($threadCount > 0) {
                $viralityMult = min(2.0 + ($threadCount - 1) * 1.0, 5.0);
            }

            $effective = $tierMult * $streakMult * $communityMult * $viralityMult;
            $effective = min($effective, 15.0); // Cap at 15x

            $finalScore = $baseScore * $effective;
            $totalFinalScore += $finalScore;

            $payouts[] = [
                "user_id" => $userId,
                "base_score" => $baseScore,
                "tier_multiplier" => $tierMult,
                "streak_multiplier" => $streakMult,
                "community_multiplier" => $communityMult,
                "virality_multiplier" => $viralityMult,
                "effective_multiplier" => $effective,
                "final_score" => $finalScore,
            ];
        }

        // Distribute proportionally
        foreach ($payouts as $p) {
            $share = $totalFinalScore > 0 ? ($p["final_score"] / $totalFinalScore) : 0;
            $payoutAmount = round($share * (float) $pool->total_amount, 2);

            CreatorFundPayout::updateOrCreate(
                ["pool_id" => $pool->id, "user_id" => $p["user_id"]],
                array_merge($p, [
                    "pool_id" => $pool->id,
                    "payout_amount" => $payoutAmount,
                    "payout_currency" => $pool->currency,
                    "status" => "pending",
                ])
            );
        }

        $pool->update(["is_distributed" => true, "distributed_at" => now()]);

        $this->info("Distributed {$pool->currency} " . number_format((float) $pool->total_amount) . " to " . count($payouts) . " creators for {$month}.");
        return Command::SUCCESS;
    }
}
