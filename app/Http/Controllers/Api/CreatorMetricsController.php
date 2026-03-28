<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorScore;
use App\Models\CreatorStreak;
use App\Models\Post;
use App\Models\PostDraft;
use App\Models\ViewerStreak;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function scoreHistory(int $id): JsonResponse
    {
        $history = \DB::table('creator_score_history')
            ->where('user_id', $id)
            ->orderByDesc('snapshot_date')
            ->limit(12)
            ->get()
            ->map(fn ($h) => [
                'snapshot_date' => $h->snapshot_date,
                'score' => (float) $h->score,
                'tier' => $h->tier,
                'component_scores' => json_decode($h->component_scores, true),
            ]);

        return response()->json(['data' => $history]);
    }

    public function viralAssists(int $id): JsonResponse
    {
        $count = \DB::table('viral_assists')->where('sharer_user_id', $id)->count();
        return response()->json(['data' => ['count' => $count]]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $tier = $request->query('tier');
        $query = \App\Models\CreatorScore::with('user:id,name')
            ->orderByDesc('score')
            ->limit(50);
        if ($tier) {
            $query->where('tier', $tier);
        }
        $creators = $query->get()->map(fn ($cs) => [
            'user_id' => $cs->user_id,
            'name' => $cs->user->name ?? '',
            'score' => (float) $cs->score,
            'tier' => $cs->tier,
        ]);
        return response()->json(['data' => $creators]);
    }

    public function postingNudge(int $id): JsonResponse
    {
        $streak = CreatorStreak::where('user_id', $id)->first();
        $lastPost = Post::where('user_id', $id)->latest()->first();
        $hoursSinceLastPost = $lastPost ? now()->diffInHours($lastPost->created_at) : 999;

        // Determine nudge type
        if ($streak && $streak->is_frozen) {
            $nudge = [
                'message' => "Your {$streak->current_streak_days}-day streak is frozen. Post now to resume it!",
                'message_sw' => "Mfululizo wako wa siku {$streak->current_streak_days} umegandishwa. Chapisha sasa kuendelea!",
                'nudge_type' => 'streak_frozen',
                'hours_until_streak_expiry' => 0,
            ];
        } elseif ($streak && $hoursSinceLastPost >= 36) {
            $hoursLeft = max(0, 48 - (int) $hoursSinceLastPost);
            $nudge = [
                'message' => "Post in the next {$hoursLeft} hours to keep your {$streak->current_streak_days}-day streak alive!",
                'message_sw' => "Chapisha ndani ya masaa {$hoursLeft} ili kudumisha mfululizo wako wa siku {$streak->current_streak_days}!",
                'nudge_type' => 'streak_warning',
                'hours_until_streak_expiry' => $hoursLeft,
            ];
        } else {
            // Best time to post based on audience activity
            $peakHour = DB::table('user_events')
                ->selectRaw("EXTRACT(HOUR FROM timestamp) as hour, COUNT(*) as cnt")
                ->where('creator_id', $id)
                ->where('event_type', 'view')
                ->where('timestamp', '>=', now()->subDays(14))
                ->groupByRaw("EXTRACT(HOUR FROM timestamp)")
                ->orderByDesc('cnt')
                ->first();

            $bestHour = $peakHour ? (int) $peakHour->hour : 19;
            $nudge = [
                'message' => "Your followers are most active at {$bestHour}:00. Post then for maximum reach!",
                'message_sw' => "Wafuatiliaji wako wanafanya kazi zaidi saa {$bestHour}:00. Chapisha wakati huo!",
                'nudge_type' => 'peak_hour',
                'suggested_hour' => $bestHour,
            ];
        }

        return response()->json(['data' => $nudge]);
    }

    public function contentCalendar(int $id): JsonResponse
    {
        $draftsCount = PostDraft::where('user_id', $id)->count();
        $postsThisWeek = Post::where('user_id', $id)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();
        $scheduledCount = Post::where('user_id', $id)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>', now())
            ->count();

        // Best time suggestion based on audience activity
        $peakHour = DB::table('user_events')
            ->selectRaw("EXTRACT(HOUR FROM timestamp) as hour, COUNT(*) as cnt")
            ->where('creator_id', $id)
            ->where('event_type', 'view')
            ->where('timestamp', '>=', now()->subDays(14))
            ->groupByRaw("EXTRACT(HOUR FROM timestamp)")
            ->orderByDesc('cnt')
            ->first();

        return response()->json(['data' => [
            'drafts_count' => $draftsCount,
            'posts_this_week' => $postsThisWeek,
            'scheduled_count' => $scheduledCount,
            'suggested_post_time' => $peakHour ? sprintf('%02d:00', (int) $peakHour->hour) : '19:00',
        ]]);
    }


    public function resumeViewerStreak(int $id): JsonResponse
    {
        $streak = ViewerStreak::where("user_id", $id)->first();

        if (!$streak) {
            // Create a new streak if none exists
            $streak = ViewerStreak::create([
                "user_id" => $id,
                "current_streak_days" => 1,
                "longest_streak_days" => 1,
                "last_active_date" => now()->toDateString(),
                "is_frozen" => false,
                "frozen_at" => null,
            ]);

            return response()->json([
                "success" => true,
                "message" => "Streak started",
                "data" => [
                    "user_id" => $streak->user_id,
                    "current_streak_days" => $streak->current_streak_days,
                    "longest_streak_days" => $streak->longest_streak_days,
                    "last_active_date" => $streak->last_active_date?->toDateString(),
                    "is_frozen" => false,
                ],
            ]);
        }

        if (!$streak->is_frozen) {
            // Already active, just update last_active_date
            $streak->update(["last_active_date" => now()->toDateString()]);

            return response()->json([
                "success" => true,
                "message" => "Streak already active",
                "data" => [
                    "user_id" => $streak->user_id,
                    "current_streak_days" => $streak->current_streak_days,
                    "longest_streak_days" => $streak->longest_streak_days,
                    "last_active_date" => $streak->last_active_date?->toDateString(),
                    "is_frozen" => false,
                ],
            ]);
        }

        // Resume frozen streak
        $streak->update([
            "is_frozen" => false,
            "frozen_at" => null,
            "last_active_date" => now()->toDateString(),
        ]);

        return response()->json([
            "success" => true,
            "message" => "Streak resumed",
            "data" => [
                "user_id" => $streak->user_id,
                "current_streak_days" => $streak->current_streak_days,
                "longest_streak_days" => $streak->longest_streak_days,
                "last_active_date" => $streak->last_active_date?->toDateString(),
                "is_frozen" => false,
            ],
        ]);
    }
}