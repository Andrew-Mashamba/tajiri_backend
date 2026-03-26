<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorScore;
use App\Models\CreatorStreak;
use App\Models\ViewerStreak;
use Illuminate\Http\JsonResponse;

class CreatorMetricsController extends Controller
{
    public function score(int $id): JsonResponse
    {
        $score = CreatorScore::where("user_id", $id)->first();
        return response()->json(["data" => $score ? [
            "user_id" => $score->user_id,
            "score" => $score->score,
            "tier" => $score->tier,
            "community_score" => $score->community_score,
            "quality_score" => $score->quality_score,
            "consistency_score" => $score->consistency_score,
            "tier_multiplier" => $score->tier_multiplier,
            "computed_at" => $score->computed_at?->toIso8601String(),
        ] : [
            "user_id" => $id, "score" => 0, "tier" => "rising",
            "community_score" => 0, "quality_score" => 0,
            "consistency_score" => 0, "tier_multiplier" => 1.0, "computed_at" => null,
        ]]);
    }

    public function creatorStreak(int $id): JsonResponse
    {
        $streak = CreatorStreak::where("user_id", $id)->first();
        return response()->json(["data" => $streak ? [
            "user_id" => $streak->user_id,
            "current_streak_days" => $streak->current_streak_days,
            "longest_streak_days" => $streak->longest_streak_days,
            "last_post_at" => $streak->last_post_at?->toIso8601String(),
            "banked_skip_days" => $streak->banked_skip_days,
            "is_frozen" => $streak->is_frozen,
            "streak_multiplier" => $streak->streak_multiplier,
        ] : [
            "user_id" => $id, "current_streak_days" => 0, "longest_streak_days" => 0,
            "last_post_at" => null, "banked_skip_days" => 0,
            "is_frozen" => false, "streak_multiplier" => 1.0,
        ]]);
    }

    public function viewerStreak(int $id): JsonResponse
    {
        $streak = ViewerStreak::where("user_id", $id)->first();
        return response()->json(["data" => $streak ? [
            "user_id" => $streak->user_id,
            "current_streak_days" => $streak->current_streak_days,
            "longest_streak_days" => $streak->longest_streak_days,
            "last_active_date" => $streak->last_active_date?->toDateString(),
            "is_frozen" => $streak->is_frozen,
        ] : [
            "user_id" => $id, "current_streak_days" => 0,
            "longest_streak_days" => 0, "last_active_date" => null, "is_frozen" => false,
        ]]);
    }

    public function fundPayout(int $id): JsonResponse
    {
        $score = CreatorScore::where("user_id", $id)->first();
        $streak = CreatorStreak::where("user_id", $id)->first();
        return response()->json(["data" => [
            "user_id" => $id,
            "current_month" => now()->format("Y-m"),
            "projected_score" => 0,
            "projected_payout" => 0,
            "tier" => $score->tier ?? "rising",
            "multipliers" => [
                "tier" => $score->tier_multiplier ?? 1.0,
                "streak" => $streak->streak_multiplier ?? 1.0,
                "community" => 1.0, "virality" => 1.0,
                "effective" => ($score->tier_multiplier ?? 1.0) * ($streak->streak_multiplier ?? 1.0),
                "capped" => false,
            ],
        ]]);
    }
}
