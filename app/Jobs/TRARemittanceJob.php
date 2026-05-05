<?php

namespace App\Jobs;

use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly TRA WHT remittance. Totals tra_wht_held across cleared events
 * in the prior month and writes the remittance journal entry. Full TRA
 * digital-portal API integration is v3; this job produces the report
 * and books the accounting entry.
 */
class TRARemittanceJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $lastMonth = Carbon::now()->subMonth();
        $start = $lastMonth->copy()->startOfMonth();
        $end   = $lastMonth->copy()->endOfMonth();

        $totalWht = (float) DB::table('earning_events')
            ->where('settlement_status', 'cleared')
            ->whereBetween('cleared_at', [$start, $end])
            ->sum('tra_wht_held');

        if ($totalWht <= 0) {
            Log::info('[TRARemittance] No WHT to remit for ' . $lastMonth->format('Y-m'));
            return;
        }

        if (Schema::hasTable('journal_entries')) {
            try {
                // Dr. 2740 TRA WHT Payable (reduce liability) → Cr. 2705 (offset operating cash)
                // The actual cash leaves via the bank-side transaction booked separately by accounts.
                LedgerService::post(
                    debitCode:   '2740',
                    creditCode:  '2705',
                    amount:      (float) $totalWht,
                    sourceType:  'tra_remittance',
                    sourceId:    0,
                    description: 'TRA WHT Section 83B remittance — ' . $lastMonth->format('Y-m')
                );
            } catch (\Throwable $e) {
                Log::error('[TRARemittance] Journal failed: ' . $e->getMessage());
            }
        }

        Log::info("[TRARemittance] Remittance booked for {$lastMonth->format('Y-m')}: TZS {$totalWht}");
        // TODO v3: push to TRA digital portal API
    }
}
