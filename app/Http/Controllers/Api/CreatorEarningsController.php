<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesUserProfileFromSanctumUser;
use App\Http\Controllers\Controller;
use App\Models\CreatorsFundPeriod;
use App\Models\CreatorTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cross-stream creator earnings endpoints.
 *
 *   GET  /api/users/me/earnings           dashboard (Task 49)
 *   GET  /api/users/me/earnings/events    per-event provenance (Task 49)
 *   GET  /api/users/me/earnings/tax       YTD WHT + invoices (Task 50)
 *   POST /api/users/me/earnings/disputes  file a dispute on an event (Task 51)
 */
class CreatorEarningsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        if ($userId === 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }

        $streams = ['engagement', 'fan_funding', 'marketplace', 'brand_deal', 'live_gifts', 'affiliate'];
        $breakdown = [];
        foreach ($streams as $stream) {
            $row = DB::table('earning_events')
                ->where('target_user_id', $userId)
                ->where('stream', $stream)
                ->where('is_chargeable', true)
                ->selectRaw("
                    SUM(CASE WHEN settlement_status = 'cleared' THEN net_to_creator ELSE 0 END) as cleared_tsh,
                    SUM(CASE WHEN settlement_status = 'pending' THEN net_to_creator ELSE 0 END) as pending_tsh,
                    COUNT(*) as event_count
                ")
                ->first();
            $breakdown[$stream] = [
                'cleared_tsh' => $row ? round((float) $row->cleared_tsh, 2) : 0.0,
                'pending_tsh' => $row ? round((float) $row->pending_tsh, 2) : 0.0,
                'event_count' => $row ? (int) $row->event_count : 0,
            ];
        }

        $totalCleared = collect($breakdown)->sum('cleared_tsh');
        $totalPending = collect($breakdown)->sum('pending_tsh');

        $period = CreatorsFundPeriod::currentOpen();
        $fundPoint = $period
            ? DB::table('creators_fund_points')
                ->where('period_id', $period->id)
                ->where('user_id', $userId)
                ->first()
            : null;
        $estimatedThisPeriod = null;
        if ($period && $fundPoint && $period->fund_per_point) {
            $estimatedThisPeriod = round((float) $fundPoint->points * (float) $period->fund_per_point, 2);
        }

        $tier = CreatorTier::forUser($userId);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id'                    => $userId,
                'total_cleared_tsh'          => round($totalCleared, 2),
                'total_pending_tsh'          => round($totalPending, 2),
                'estimated_this_period_tsh'  => $estimatedThisPeriod,
                'currency'                   => 'TSh',
                'tier'                       => $tier->tier,
                'is_mwanzo_active'           => $tier->isMwanzoActive(),
                'mwanzo_expires_at'          => $tier->mwanzo_expires_at?->toIso8601String(),
                'monetization_paused'        => $tier->monetization_paused,
                'breakdown_by_stream'        => $breakdown,
                'current_period'             => $period ? [
                    'period_id'      => $period->id,
                    'period_start'   => $period->period_start?->toIso8601String(),
                    'period_end'     => $period->period_end?->toIso8601String(),
                    'phase'          => $period->phase,
                    'fund_size_tsh'  => (float) $period->fund_size_tsh,
                    'fund_per_point' => $period->fund_per_point ? (float) $period->fund_per_point : null,
                    'your_points'    => $fundPoint ? (float) $fundPoint->points : 0.0,
                ] : null,
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $userId  = (int) $request->input('user_id');
        $page    = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 20), 100);
        $stream  = $request->input('stream');
        $status  = $request->input('status');

        $query = DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->orderByDesc('occurred_at');
        if ($stream) {
            $query->where('stream', $stream);
        }
        if ($status) {
            $query->where('settlement_status', $status);
        }

        $events = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($events->items())->map(fn ($e) => [
            'event_id'          => $e->id,
            'occurred_at'       => $e->occurred_at,
            'post_id'           => $e->post_id,
            'stream'            => $e->stream,
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
            'disbursed_at'      => $e->disbursed_at ?? null,
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
     * GET /api/users/me/earnings/tax — YTD WHT + per-month breakdown.
     */
    public function tax(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        $year   = (int) $request->input('year', (int) now()->year);

        $monthly = DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->where('settlement_status', 'cleared')
            ->whereYear('cleared_at', $year)
            ->selectRaw("
                EXTRACT(MONTH FROM cleared_at) as month,
                SUM(net_to_creator) as net_tsh,
                SUM(tra_wht_held) as wht_tsh,
                COUNT(*) as event_count
            ")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $totalNet = $monthly->sum(fn ($r) => (float) $r->net_tsh);
        $totalWht = $monthly->sum(fn ($r) => (float) $r->wht_tsh);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id'        => $userId,
                'year'           => $year,
                'currency'       => 'TSh',
                'total_net_tsh'  => round($totalNet, 2),
                'total_wht_tsh'  => round($totalWht, 2),
                'monthly'        => $monthly->map(fn ($r) => [
                    'month'       => (int) $r->month,
                    'net_tsh'     => round((float) $r->net_tsh, 2),
                    'wht_tsh'     => round((float) $r->wht_tsh, 2),
                    'event_count' => (int) $r->event_count,
                ])->all(),
            ],
        ]);
    }

    /**
     * GET /api/users/me/earnings/by-metric
     *
     * Group all chargeable earning_events for the calling user by
     * (metric, actor_role) for the given period. Powers the per-event
     * breakdown table on the Posts category page (and any other
     * category that wants to show the B+C attribution rows).
     *
     * Query params:
     *   user_id (required)
     *   period   one of week|month|quarter|year (default month)
     *   stream   optional filter (e.g. engagement, fan_funding)
     *
     * Response shape:
     *   {
     *     period_start: ISO date,
     *     period_end:   ISO date,
     *     currency:     "TSh",
     *     totals: { gross_tsh, net_tsh, event_count },
     *     rows: [
     *       { metric, actor_role, event_count, raw_count_total,
     *         gross_tsh, platform_take_tsh, wht_tsh, net_tsh }
     *     ]
     *   }
     */
    public function byMetric(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        if ($userId === 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }

        $period = (string) $request->input('period', 'month');
        $stream = $request->input('stream');

        [$start, $end] = match ($period) {
            'week'    => [now()->startOfWeek(), now()->endOfWeek()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year'    => [now()->startOfYear(), now()->endOfYear()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $query = DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->where('is_chargeable', true)
            ->whereBetween('occurred_at', [$start, $end]);
        if (is_string($stream) && $stream !== '') {
            $query->where('stream', $stream);
        }

        $rows = $query
            ->selectRaw('
                metric,
                actor_role,
                stream,
                COUNT(*) AS event_count,
                SUM(raw_count) AS raw_count_total,
                SUM(gross_credit) AS gross_tsh,
                SUM(platform_take) AS platform_take_tsh,
                SUM(tra_wht_held) AS wht_tsh,
                SUM(net_to_creator) AS net_tsh
            ')
            ->groupBy('metric', 'actor_role', 'stream')
            ->orderBy('metric')
            ->orderBy('actor_role')
            ->get();

        $totalGross = $rows->sum(fn ($r) => (float) $r->gross_tsh);
        $totalNet   = $rows->sum(fn ($r) => (float) $r->net_tsh);
        $totalCount = $rows->sum(fn ($r) => (int) $r->event_count);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id'      => $userId,
                'period'       => $period,
                'period_start' => $start->toIso8601String(),
                'period_end'   => $end->toIso8601String(),
                'stream'       => $stream,
                'currency'     => 'TSh',
                'totals' => [
                    'gross_tsh'   => round($totalGross, 2),
                    'net_tsh'     => round($totalNet, 2),
                    'event_count' => $totalCount,
                ],
                'rows' => $rows->map(fn ($r) => [
                    'metric'             => $r->metric,
                    'actor_role'         => $r->actor_role,
                    'stream'             => $r->stream,
                    'event_count'        => (int) $r->event_count,
                    'raw_count_total'    => (int) $r->raw_count_total,
                    'gross_tsh'          => round((float) $r->gross_tsh, 2),
                    'platform_take_tsh'  => round((float) $r->platform_take_tsh, 2),
                    'wht_tsh'            => round((float) $r->wht_tsh, 2),
                    'net_tsh'            => round((float) $r->net_tsh, 2),
                ])->all(),
            ],
        ]);
    }

    /**
     * GET /api/users/me/posts/earnings
     *
     * List the calling user's posts that earned in the given period,
     * with per-post totals + a compact metric summary. Powers the
     * Posts category drill-down (creator → tap "Posts" → list of posts
     * with their earnings, tap a post → existing PostEarningsScreen).
     *
     * Query params:
     *   user_id (required)
     *   period   week|month|quarter|year (default month)
     *   page     1-based page index (default 1)
     *   per_page items per page (default 20, max 100)
     */
    public function myPostsEarnings(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        if ($userId === 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }

        $period = (string) $request->input('period', 'month');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        [$start, $end] = match ($period) {
            'week'    => [now()->startOfWeek(), now()->endOfWeek()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year'    => [now()->startOfYear(), now()->endOfYear()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };

        // Posts that earned in the period, sorted by net descending.
        $totalsSub = DB::table('earning_events')
            ->select('post_id')
            ->selectRaw('SUM(net_to_creator) AS total_net')
            ->selectRaw('COUNT(*) AS event_count')
            ->where('target_user_id', $userId)
            ->whereNotNull('post_id')
            ->where('is_chargeable', true)
            ->whereBetween('occurred_at', [$start, $end])
            ->groupBy('post_id');

        $postsQuery = DB::table('posts')
            ->joinSub($totalsSub, 'totals', 'totals.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->select(
                'posts.id',
                'posts.content',
                'posts.post_type',
                'posts.created_at',
                'totals.total_net',
                'totals.event_count'
            )
            ->orderByDesc('totals.total_net');

        $totalCount = (clone $postsQuery)->count();
        $posts = $postsQuery
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $postIds = $posts->pluck('id')->all();

        // Per-post per-metric breakdown (one query for the page).
        $metrics = $postIds === []
            ? collect()
            : DB::table('earning_events')
                ->whereIn('post_id', $postIds)
                ->where('target_user_id', $userId)
                ->where('is_chargeable', true)
                ->whereBetween('occurred_at', [$start, $end])
                ->select('post_id', 'metric')
                ->selectRaw('SUM(raw_count) AS raw_count_total')
                ->selectRaw('SUM(net_to_creator) AS net_tsh')
                ->selectRaw('COUNT(*) AS event_count')
                ->groupBy('post_id', 'metric')
                ->get();

        $metricsByPost = $metrics->groupBy('post_id');

        // Thumbnails (one query for the page).
        $thumbs = $postIds === []
            ? collect()
            : DB::table('post_media')
                ->whereIn('post_id', $postIds)
                ->select('post_id', 'thumbnail_path', 'file_path')
                ->orderBy('post_id')
                ->get()
                ->groupBy('post_id');

        $rows = $posts->map(function ($p) use ($metricsByPost, $thumbs) {
            $perMetric = ($metricsByPost[$p->id] ?? collect())
                ->map(fn ($m) => [
                    'metric'           => $m->metric,
                    'raw_count_total'  => (int) $m->raw_count_total,
                    'event_count'      => (int) $m->event_count,
                    'net_tsh'          => round((float) $m->net_tsh, 2),
                ])
                ->sortByDesc('net_tsh')
                ->values()
                ->all();

            $media = $thumbs[$p->id] ?? collect();
            $first = $media->first();
            $thumbnailUrl = $first?->thumbnail_path ?? $first?->file_path;

            $excerpt = $p->content !== null && strlen($p->content) > 120
                ? substr($p->content, 0, 117) . '...'
                : $p->content;

            return [
                'post_id'        => (int) $p->id,
                'post_type'      => $p->post_type,
                'content'        => $excerpt,
                'thumbnail_url'  => $thumbnailUrl,
                'created_at'     => $p->created_at,
                'total_net_tsh'  => round((float) $p->total_net, 2),
                'event_count'    => (int) $p->event_count,
                'metrics'        => $perMetric,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'meta'    => [
                'period'       => $period,
                'period_start' => $start->toIso8601String(),
                'period_end'   => $end->toIso8601String(),
                'page'         => $page,
                'per_page'     => $perPage,
                'last_page'    => max(1, (int) ceil($totalCount / $perPage)),
                'total'        => $totalCount,
                'currency'     => 'TSh',
            ],
        ]);
    }

    /**
     * POST /api/users/me/earnings/disputes — file a dispute on a specific event.
     */
    public function fileDispute(Request $request): JsonResponse
    {
        $userId  = (int) $request->input('user_id');
        $eventId = (int) $request->input('event_id');
        $reason  = (string) $request->input('reason', '');

        if (!$eventId || !$reason) {
            return response()->json(['success' => false, 'message' => 'event_id and reason required'], 422);
        }

        $event = DB::table('earning_events')->where('id', $eventId)->first();
        if (!$event || (int) $event->target_user_id !== $userId) {
            return response()->json(['success' => false, 'message' => 'Event not found or not owned'], 404);
        }

        $disputeId = DB::table('earnings_disputes')->insertGetId([
            'event_id'    => $eventId,
            'user_id'     => $userId,
            'reason'      => $reason,
            'status'      => 'open',
            'filed_at'    => now(),
            'resolved_at' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'dispute_id' => $disputeId,
                'status'     => 'open',
                'sla_days'   => $event->stream === 'fan_funding' ? 2 : 5,
            ],
        ]);
    }
}
