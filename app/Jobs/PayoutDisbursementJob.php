<?php

namespace App\Jobs;

use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily disbursement: aggregates cleared, undisbursed earnings per creator,
 * credits ≥ TZS 5,000 to the creator's Tajiri Pay wallet.
 */
class PayoutDisbursementJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const MIN_PAYOUT_TSH = 5000.0;

    public function handle(): void
    {
        $creatorBalances = DB::table('earning_events')
            ->where('settlement_status', 'cleared')
            ->whereNull('disbursed_at')
            ->where('is_chargeable', true)
            ->groupBy('target_user_id')
            ->selectRaw('target_user_id, SUM(net_to_creator) as total_cleared')
            ->having('total_cleared', '>=', self::MIN_PAYOUT_TSH)
            ->get();

        foreach ($creatorBalances as $row) {
            $this->disburseTo((int) $row->target_user_id, (float) $row->total_cleared);
        }

        Log::info("[PayoutDisbursement] Processed " . count($creatorBalances) . " creator payouts.");
    }

    private function disburseTo(int $userId, float $amount): void
    {
        DB::transaction(function () use ($userId, $amount) {
            if (!Schema::hasTable('wallets')) {
                Log::warning("[PayoutDisbursement] wallets table missing — Tajiri Pay module not deployed");
                return;
            }

            $wallet = DB::table('wallets')->where('user_id', $userId)->first();
            if (!$wallet) {
                Log::warning("[PayoutDisbursement] No wallet for user #{$userId}");
                return;
            }

            $txId = 'EARN-' . strtoupper(uniqid());
            $balanceBefore = (float) $wallet->balance;
            $balanceAfter  = $balanceBefore + $amount;

            // Journal first so we can link the wallet_transaction.journal_entry_id.
            $journalEntryId = null;
            if (Schema::hasTable('journal_entries')) {
                try {
                    // Dr. 2720 Cleared Creator Earnings (Payable) — reduce liability
                    // Cr. (TODO: wallet asset code) — for now we credit 2720 against 2705
                    // pattern is fine: we are settling the cleared liability.
                    // Real "asset" side is the operating cash that funds the wallet — booked
                    // in Tajiri Pay's separate cash management flow. Here we record the
                    // creator-side liability discharge.
                    $journalEntryId = LedgerService::post(
                        debitCode:   '2720',
                        creditCode:  '2705',
                        amount:      $amount,
                        sourceType:  'wallet_transaction',
                        sourceId:    0,
                        description: "Disbursement to wallet — user #{$userId} tx {$txId}"
                    );
                } catch (\Throwable $e) {
                    Log::error("[PayoutDisbursement] Journal failed for user {$userId}: " . $e->getMessage());
                }
            }

            DB::table('wallet_transactions')->insert([
                'transaction_id'   => $txId,
                'wallet_id'        => $wallet->id,
                'user_id'          => $userId,
                'type'             => 'creator_earnings',
                'amount'           => $amount,
                'fee'              => 0,
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
                'status'           => 'completed',
                'payment_method'   => 'wallet',
                'description'      => 'Creator earnings disbursement',
                'completed_at'     => now(),
                'journal_entry_id' => $journalEntryId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance'    => $balanceAfter,
                'updated_at' => now(),
            ]);

            DB::table('earning_events')
                ->where('target_user_id', $userId)
                ->where('settlement_status', 'cleared')
                ->whereNull('disbursed_at')
                ->update(['disbursed_at' => now()]);
        });
    }
}
