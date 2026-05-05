<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorsFundPeriod;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Earnings read endpoints. Replaces the old read-time formula with
 * event-ledger-backed aggregates per strategy §1.2 + §9.
 *
 * Endpoints:
 *   GET /api/posts/{postId}/earnings          (Task 47 — rewritten)
 *   GET /api/posts/{postId}/earnings/events   (Task 48 — provenance ledger)
 *   POST /api/posts/{postId}/discovery-mode   (Task 52 — opt-in 30 days)
 */
class PostEarningsController extends Controller
{
    /**
     * GET /api/posts/{postId}/earnings — aggregated earnings.
     */
    public function earnings(int $postId): JsonResponse
    {
        $post = Post::select(['id', 'user_id'])->find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        $actuals = DB::table('earning_events')
            ->where('post_id', $postId)
            ->where('target_user_id', $post->user_id)
            ->where('is_chargeable', true)
            ->selectRaw("
                metric,
                SUM(CASE WHEN settlement_status = 'cleared' THEN net_to_creator ELSE 0 END) as cleared_tsh,
                SUM(CASE WHEN settlement_status = 'pending' THEN net_to_creator ELSE 0 END) as pending_tsh,
                SUM(raw_count) as total_count
            ")
            ->groupBy('metric')
            ->get()
            ->keyBy('metric');

        $period = CreatorsFundPeriod::currentOpen();
        $fundPerPoint = $period ? (float) $period->fund_per_point : null;

        $postPoints = (float) DB::table('earning_events')
            ->where('post_id', $postId)
            ->where('target_user_id', $post->user_id)
            ->where('is_chargeable', true)
            ->where('stream', 'engagement')
            ->where('settlement_status', 'pending')
            ->sum('gross_credit');

        $estimatedFromPool = $fundPerPoint && $postPoints
            ? round($postPoints * $fundPerPoint, 2)
            : null;

        $breakdown = [];
        foreach (['view', 'reaction', 'comment', 'reply', 'share', 'save', 'watch_second', 'derivative_royalty', 'follow_from_post', 'subscribe_from_post'] as $metric) {
            $row = $actuals->get($metric);
            $breakdown[$metric] = [
                'count'       => $row ? (int) $row->total_count : 0,
                'cleared_tsh' => $row ? (float) $row->cleared_tsh : 0.0,
                'pending_tsh' => $row ? (float) $row->pending_tsh : 0.0,
            ];
        }

        $totalCleared = collect($breakdown)->sum('cleared_tsh');
        $totalPending = collect($breakdown)->sum('pending_tsh');

        return response()->json([
            'success' => true,
            'data' => [
                'post_id'            => $postId,
                'total_cleared_tsh'  => round($totalCleared, 2),
                'total_pending_tsh'  => round($totalPending, 2),
                'estimated_pool_tsh' => $estimatedFromPool,
                'fund_per_point'     => $fundPerPoint,
                'currency'           => 'TSh',
                'settlement_note'    => 'Pending amounts settle after 30 days. Pool estimates update weekly.',
                'breakdown'          => $breakdown,
            ],
        ]);
    }

    /**
     * GET /api/posts/{postId}/earnings/events — paginated provenance ledger.
     */
    public function earningsEvents(int $postId, Request $request): JsonResponse
    {
        $post = Post::select(['id', 'user_id'])->find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        $page    = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 20), 100);

        $events = DB::table('earning_events')
            ->where('post_id', $postId)
            ->where('target_user_id', $post->user_id)
            ->orderByDesc('occurred_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($events->items())->map(fn ($e) => [
            'event_id'          => $e->id,
            'occurred_at'       => $e->occurred_at,
            'metric'            => $e->metric,
            'actor_role'        => $e->actor_role,
            'raw_count'         => $e->raw_count,
            'rate_tsh'          => (float) $e->rate_tsh,
            'multipliers'       => is_string($e->multipliers) ? json_decode($e->multipliers, true) : $e->multipliers,
            'gross_credit'      => (float) $e->gross_credit,
            'platform_take'     => (float) $e->platform_take,
            'tra_wht_held'      => (float) $e->tra_wht_held,
            'net_to_creator'    => (float) $e->net_to_creator,
            'is_chargeable'     => (bool) $e->is_chargeable,
            'charge_reason'     => $e->charge_reason,
            'settlement_status' => $e->settlement_status,
            'cleared_at'        => $e->cleared_at,
            'funding_source'    => $e->funding_source,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'current_page' => $events->currentPage(),
                'last_page'    => $events->lastPage(),
                'per_page'     => $perPage,
                'total'        => $events->total(),
            ],
        ]);
    }

    /**
     * POST /api/posts/{postId}/discovery-mode — opt the post into Discovery Mode for 30 days.
     * Strategy §3.5.
     */
    public function discoveryMode(int $postId, Request $request): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        }

        $userId = (int) $request->input('user_id');
        if ((int) $post->user_id !== $userId) {
            return response()->json(['success' => false, 'message' => 'Only the post author can opt into Discovery Mode'], 403);
        }

        DB::table('posts')->where('id', $postId)->update([
            'is_discovery_mode' => true,
            'discovery_mode_until' => now()->addDays(30),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'post_id'              => $postId,
                'discovery_mode_until' => now()->addDays(30)->toIso8601String(),
                'royalty_trade_pct'    => 30.0,
                'note'                 => 'Discovery Mode active for 30 days. Engagement-pool credits reduced 30% during the boost.',
            ],
        ]);
    }
}
