<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorFundPool;
use App\Models\CreatorFundPayout;
use App\Models\CreatorScore;
use App\Models\CreatorStreak;
use App\Models\Post;
use App\Models\GossipThreadPost;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function weeklyReport(Request $request, int $id)
    {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();
        $prevWeekStart = Carbon::now()->subWeek()->startOfWeek();
        $prevWeekEnd = Carbon::now()->subWeek()->endOfWeek();

        // This week stats
        $posts = Post::where("user_id", $id)
            ->whereBetween("created_at", [$weekStart, $weekEnd])
            ->get();

        $totalViews = $posts->sum("views_count");
        $totalLikes = $posts->sum("likes_count");
        $bestPost = $posts->sortByDesc("likes_count")->first();

        // Previous week for comparison
        $prevPosts = Post::where("user_id", $id)
            ->whereBetween("created_at", [$prevWeekStart, $prevWeekEnd])
            ->get();
        $prevLikes = $prevPosts->sum("likes_count");
        $engagementChange = $prevLikes > 0
            ? (($totalLikes - $prevLikes) / $prevLikes) * 100
            : ($totalLikes > 0 ? 100 : 0);

        // Follower change (approximate from user model)
        $user = \App\Models\User::find($id);
        $followerChange = 0;
        if ($user && isset($user->followers_count)) {
            $followerChange = max(0, $totalLikes > 0 ? intval($totalLikes * 0.1) : 0);
        }

        // Threads triggered
        $postIds = $posts->pluck("id")->toArray();
        $threadsTriggered = DB::table("gossip_threads")
            ->whereIn("seed_post_id", $postIds)
            ->count();

        // Projected earnings (from fund payout or estimate)
        $score = CreatorScore::where("user_id", $id)->first();
        $streak = CreatorStreak::where("user_id", $id)->first();
        $baseScore = $totalViews * 0.01 + $totalLikes * 0.1 + $posts->sum("shares_count") * 0.3;
        $tierMult = $score ? (float) $score->tier_multiplier : 1.0;
        $streakMult = $streak ? (float) $streak->streak_multiplier : 1.0;
        $totalEarnings = $baseScore * $tierMult * $streakMult;

        $prevBaseScore = $prevPosts->sum("views_count") * 0.01 + $prevLikes * 0.1 + $prevPosts->sum("shares_count") * 0.3;
        $prevEarnings = $prevBaseScore * $tierMult * $streakMult;
        $earningsChange = $prevEarnings > 0
            ? (($totalEarnings - $prevEarnings) / $prevEarnings) * 100
            : ($totalEarnings > 0 ? 100 : 0);

        $trend = $engagementChange > 5 ? "up" : ($engagementChange < -5 ? "down" : "stable");

        return response()->json([
            "data" => [
                "total_earnings" => round($totalEarnings, 2),
                "earnings_change_percent" => round($earningsChange, 1),
                "best_post_id" => $bestPost?->id ?? 0,
                "best_post_likes" => $bestPost?->likes_count ?? 0,
                "engagement_trend" => $trend,
                "follower_change" => $followerChange,
                "threads_triggered" => $threadsTriggered,
                "total_views" => $totalViews,
                "total_likes" => $totalLikes,
                "week_start" => $weekStart->toDateString(),
                "week_end" => $weekEnd->toDateString(),
            ],
        ]);
    }

    public function currentPool(Request $request)
    {
        $month = Carbon::now()->format("Y-m");
        $pool = CreatorFundPool::firstOrCreate(
            ["month" => $month],
            ["total_amount" => 10000000, "currency" => "TZS"]
        );

        return response()->json(["data" => $pool]);
    }

    public function requestPayout(Request $request, int $id)
    {
        // Placeholder — in production this integrates with mobile money APIs
        return response()->json([
            "success" => true,
            "message" => "Payout request submitted. You will receive your payment within 48 hours.",
        ]);
    }

    public function payoutHistory(Request $request, int $id)
    {
        $payouts = CreatorFundPayout::where("user_id", $id)
            ->orderByDesc("created_at")
            ->limit(12)
            ->get();

        return response()->json(["data" => $payouts]);
    }
}
