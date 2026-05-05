<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/creators/rate-card — public rate card per strategy §10.5.
 * Lists every metric, rate, cap, plus per-stream splits and the active
 * fund-formula parameters. 30-day-notice protection on rate cuts.
 */
class CreatorRateCardController extends Controller
{
    public function show(): JsonResponse
    {
        $rates = DB::table('creator_earnings_rates')
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->orderBy('stream')
            ->orderBy('actor_role')
            ->orderBy('metric')
            ->get();

        $byStream = $rates->groupBy('stream')->map(function ($rows) {
            return $rows->map(fn ($r) => [
                'metric'       => $r->metric,
                'actor_role'   => $r->actor_role,
                'rate_tsh'     => (float) $r->rate,
                'max_cap_tsh'  => $r->max_cap_tsh !== null ? (float) $r->max_cap_tsh : null,
                'tier_minimum' => $r->tier_minimum,
            ])->values();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => 'TSh',
                'phase'    => config('earnings.phase', 'phase_1'),
                'fund_formula' => [
                    'phase_1' => [
                        'phase_1_committed_budget_tsh' => config('earnings.phase_1_weekly_fund_tsh', 50_000_000),
                        'note' => 'Treasury-funded creator-acquisition spend. Distributed weekly proportional to attributed engagement points.',
                    ],
                    'phase_2' => [
                        'ad_share_pct'           => 0.70,
                        'pass_through_share_pct' => 0.10,
                        'floor_tsh'              => config('earnings.phase_1_weekly_fund_tsh', 50_000_000),
                        'note' => 'fund = MAX(floor, 0.70 × ad_revenue + 0.10 × pass_through_takes + treasury_topup). Floor protects creators when ads dip.',
                    ],
                ],
                'splits' => [
                    'engagement'   => '70/30 (Phase 2) — fund-distributed in Phase 1',
                    'fan_funding'  => '95/5',
                    'marketplace'  => '100/0 first 90 days, 95/5 after (Mwanzo)',
                    'brand_deal'   => '90/10',
                    'live_gifts'   => '90/10',
                ],
                'multipliers' => config('earnings_multipliers'),
                'tier_rules'  => config('creator_tiers'),
                'rates_by_stream' => $byStream,
                'rate_change_policy' => '30-day advance notice on any rate cut. Increases ship same-day.',
                'last_updated' => $rates->max('updated_at'),
            ],
        ]);
    }
}
