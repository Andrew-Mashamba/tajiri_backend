<?php

namespace App\Jobs;

use App\Models\EarningEvent;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily sweep job. Moves earning_events ≥30 days old (45 for fan_funding)
 * from settlement_status='pending' → 'cleared'. Auto-deducts TRA Section
 * 83B WHT (5%) for TZ residents per strategy §7.1. Writes corresponding
 * journal_lines entries (Dr. pending account → Cr. cleared) plus the
 * WHT credit to account 2140.
 */
class SettlementSweepJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $whtRate = (float) config('earnings.wht_rate', 0.05);
        $window  = (int) config('earnings.settlement_window_days', 30);
        $fundingWindow = (int) config('earnings.fan_funding_window_days', 45);

        $events = EarningEvent::query()
            ->where('settlement_status', 'pending')
            ->where('is_chargeable', true)
            ->where(function ($q) use ($window, $fundingWindow) {
                $q->where(function ($q1) use ($window) {
                    $q1->where('stream', '!=', 'fan_funding')
                       ->where('occurred_at', '<=', now()->subDays($window));
                })->orWhere(function ($q2) use ($fundingWindow) {
                    $q2->where('stream', 'fan_funding')
                       ->where('occurred_at', '<=', now()->subDays($fundingWindow));
                });
            })
            ->limit(5000)
            ->get();

        $cleared = 0;
        foreach ($events as $event) {
            DB::transaction(function () use ($event, $whtRate, &$cleared) {
                $taxResidency = (string) DB::table('user_profiles')
                    ->where('id', $event->target_user_id)
                    ->value('tax_residency');
                $isTzResident = $taxResidency !== 'NON_TZ';

                $wht = $isTzResident ? round($event->net_to_creator * $whtRate, 2) : 0.0;
                $netAfterWht = max(0.0, round($event->net_to_creator - $wht, 2));

                $pendingCode = match ($event->stream) {
                    'engagement'  => '2710',
                    'fan_funding' => '2711',
                    'marketplace' => '2712',
                    'brand_deal'  => '2713',
                    'live_gifts'  => '2714',
                    default       => '2710',
                };

                if (Schema::hasTable('journal_entries') && $event->net_to_creator > 0) {
                    try {
                        // Dr. pending → Cr. cleared (gross net to creator)
                        LedgerService::post(
                            debitCode:   $pendingCode,
                            creditCode:  '2720',
                            amount:      (float) $event->net_to_creator,
                            sourceType:  'earning_event',
                            sourceId:    (int) $event->id,
                            description: "Sweep clear — event {$event->id}"
                        );
                        // WHT entry: Dr. 2720 cleared (reduce payable) → Cr. 2740 TRA WHT Payable
                        if ($wht > 0) {
                            LedgerService::post(
                                debitCode:   '2720',
                                creditCode:  '2740',
                                amount:      $wht,
                                sourceType:  'earning_event',
                                sourceId:    (int) $event->id,
                                description: "WHT 5% Section 83B — event {$event->id}"
                            );
                        }
                    } catch (\Throwable $e) {
                        Log::error("[SettlementSweep] Journal failed for event {$event->id}: " . $e->getMessage());
                    }
                }

                $event->update([
                    'settlement_status' => 'cleared',
                    'cleared_at'        => now(),
                    'tra_wht_held'      => $wht,
                    'net_to_creator'    => $netAfterWht,
                ]);
                $cleared++;
            });
        }

        Log::info("[SettlementSweep] Cleared {$cleared} events");
    }
}
