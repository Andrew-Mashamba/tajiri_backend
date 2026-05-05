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

        // Generate posting tip based on data
        $postingTip = self::generatePostingTip($posts, $totalViews, $totalLikes, $threadsTriggered);

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
                "posting_tip" => $postingTip,
            ],
        ]);
    }

    private static function generatePostingTip($posts, int $totalViews, int $totalLikes, int $threadsTriggered): string
    {
        $tips = [];

        if ($posts->isEmpty()) {
            $tips[] = "You didn't post this week. Even one post keeps your streak alive and your audience engaged.";
        } elseif ($posts->count() < 3) {
            $tips[] = 'Try posting at least 3 times per week. Consistent creators earn 2-3x more from the Creator Fund.';
        }

        if ($totalViews > 0 && $totalLikes > 0) {
            $engRate = ($totalLikes / max($totalViews, 1)) * 100;
            if ($engRate < 2) {
                $tips[] = 'Your engagement rate is low. Try asking questions in your posts or using trending hashtags.';
            } elseif ($engRate > 10) {
                $tips[] = 'Great engagement rate! Your audience loves your content. Keep this style going.';
            }
        }

        if ($threadsTriggered > 0) {
            $tips[] = "Your posts sparked {$threadsTriggered} gossip threads this week! Thread-worthy content earns bonus multipliers.";
        } else {
            $tips[] = 'Post during peak hours (7-9 AM, 12-2 PM, 7-10 PM) when your audience is most active.';
        }

        // Ensure at least one tip exists
        if (empty($tips)) {
            $tips[] = 'Keep creating! Consistent posting builds your audience and maximizes Creator Fund earnings.';
        }

        return $tips[array_rand($tips)];
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
        $earnings = \App\Models\CreatorEarning::where("creator_id", $id)
            ->where("status", "pending")
            ->sum("net_amount");

        if ($earnings <= 0) {
            return response()->json(["message" => "No unpaid earnings"], 422);
        }

        $payout = \App\Models\CreatorPayout::create([
            "creator_id" => $id,
            "amount" => $earnings,
            "status" => "pending",
        ]);

        \App\Models\CreatorEarning::where("creator_id", $id)
            ->where("status", "pending")
            ->update(["status" => "paid", "paid_at" => now()]);

        return response()->json(["data" => [
            "payout_id" => $payout->id,
            "amount" => (float) $earnings,
            "status" => "pending",
            "message" => "Payout request submitted.",
        ]]);
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
