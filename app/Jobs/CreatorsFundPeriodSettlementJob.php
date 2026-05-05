<?php

namespace App\Jobs;

use App\Models\CreatorsFundPeriod;
use App\Models\EarningEvent;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly settlement job per strategy §1.2. Closes the prior period,
 * computes fund_size from the active phase formula, aggregates points,
 * computes fund_per_point, writes per-creator settlement events +
 * journal_lines, then opens the next period.
 */
class CreatorsFundPeriodSettlementJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $periods = CreatorsFundPeriod::where('status', 'open')
            ->where('period_end', '<=', now())
            ->get();

        foreach ($periods as $period) {
            $this->settle($period);
        }

        if (!CreatorsFundPeriod::currentOpen()) {
            CreatorsFundPeriod::openNextPeriod($this->activePhase());
        }
    }

    private function activePhase(): string
    {
        return config('earnings.phase', 'phase_1');
    }

    private function settle(CreatorsFundPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period->update(['status' => 'distributing']);

            $fundSize = $this->computeFundSize($period);
            $totalPoints = (float) DB::table('creators_fund_points')
                ->where('period_id', $period->id)
                ->sum('points');

            $fundPerPoint = $totalPoints > 0 ? round($fundSize / $totalPoints, 8) : 0.0;
            $eligibleCount = (int) DB::table('creators_fund_points')
                ->where('period_id', $period->id)
                ->where('points', '>', 0)
                ->count();

            $batchJournalId = null;
            if (Schema::hasTable('journal_entries') && $fundSize > 0) {
                // Dr. expense (5710 Phase 1 CAC, or 5711 Phase 2 rev-share)
                // Cr. 2705 Creators Fund — Pending Distribution
                $batchDebitCode = $period->phase === 'phase_1' ? '5710' : '5711';
                try {
                    $batchJournalId = LedgerService::post(
                        debitCode:   $batchDebitCode,
                        creditCode:  '2705',
                        amount:      $fundSize,
                        sourceType:  'creators_fund_period',
                        sourceId:    (int) $period->id,
                        description: "Creators Fund commitment — period #{$period->id} ({$period->phase})"
                    );
                } catch (\Throwable $e) {
                    Log::error('[CreatorsFundSettlement] Batch journal failed: ' . $e->getMessage());
                }
            }

            $creators = DB::table('creators_fund_points')
                ->where('period_id', $period->id)
                ->where('points', '>', 0)
                ->get();

            foreach ($creators as $row) {
                $payout = round((float) $row->points * $fundPerPoint, 2);
                DB::table('creators_fund_points')->where('id', $row->id)->update(['payout_tsh' => $payout]);

                $event = EarningEvent::create([
                    'event_uid'         => "settle:{$period->id}:user:{$row->user_id}",
                    'occurred_at'       => now(),
                    'source_type'       => 'creators_fund_period',
                    'source_id'         => $period->id,
                    'target_user_id'    => $row->user_id,
                    'actor_role'        => 'author',
                    'stream'            => 'engagement',
                    'metric'            => 'period_settlement',
                    'raw_count'         => 1,
                    'rate_tsh'          => $fundPerPoint,
                    'multipliers'       => [],
                    'gross_credit'      => $payout,
                    'platform_take'     => 0,
                    'tra_wht_held'      => 0,
                    'net_to_creator'    => $payout,
                    'is_chargeable'     => true,
                    'funding_source'    => "creators_fund_period:{$period->id}",
                    'settlement_status' => 'pending',
                ]);

                if (Schema::hasTable('journal_entries') && $payout > 0) {
                    // Dr. 2705 Creators Fund — Pending Distribution
                    // Cr. 2710 Pending Creator Earnings — Engagement
                    try {
                        LedgerService::post(
                            debitCode:   '2705',
                            creditCode:  '2710',
                            amount:      $payout,
                            sourceType:  'earning_event',
                            sourceId:    (int) $event->id,
                            description: "Distribute to user #{$row->user_id} — period #{$period->id}"
                        );
                    } catch (\Throwable $e) {
                        Log::error("[CreatorsFundSettlement] Per-creator journal failed for user {$row->user_id}: " . $e->getMessage());
                    }
                }
            }

            $period->update([
                'status'                      => 'settled',
                'fund_size_tsh'               => $fundSize,
                'total_points'                => $totalPoints,
                'fund_per_point'              => $fundPerPoint,
                'eligible_creator_count'      => $eligibleCount,
                'settled_at'                  => now(),
                'settlement_journal_batch_id' => $batchJournalId,
            ]);

            Log::info("[CreatorsFundSettlement] Period #{$period->id} ({$period->phase}) settled: fund TZS {$fundSize}, {$eligibleCount} creators, fund/point {$fundPerPoint}");
        });
    }

    /** §1.2 — fund-size formulas. */
    private function computeFundSize(CreatorsFundPeriod $period): float
    {
        if ($period->phase === 'phase_1') {
            return (float) ($period->phase_1_committed_budget_tsh
                ?? config('earnings.phase_1_weekly_fund_tsh', 50_000_000));
        }
        $adRev    = (float) ($period->ad_revenue_tsh ?? 0);
        $passThru = (float) ($period->fan_funding_take_tsh ?? 0)
            + (float) ($period->marketplace_take_tsh ?? 0)
            + (float) ($period->brand_deal_take_tsh ?? 0)
            + (float) ($period->live_gifts_take_tsh ?? 0);
        $topup    = (float) ($period->treasury_topup_tsh ?? 0);
        $floor    = (float) $period->floor_tsh;
        return max(
            $floor,
            ($period->ad_share_pct ?? 0.70) * $adRev
            + ($period->pass_through_share_pct ?? 0.10) * $passThru
            + $topup
        );
    }
}
