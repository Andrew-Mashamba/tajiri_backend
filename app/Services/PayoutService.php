<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Payout helper. Wraps Tajiri Pay wallet credit semantics so the
 * earnings pipeline doesn't need to know about wallet table internals.
 *
 * Mobile-money rail wiring (M-Pesa / Airtel Money / Tigo Pesa / MTN
 * MoMo) lives inside Tajiri Pay's existing wallet/withdrawal modules;
 * this service only initiates the wallet credit — actual withdrawal
 * routing is the user's choice in the Tajiri Pay UI.
 *
 * Strategy §6 — Tajiri Pay-native payouts.
 */
class PayoutService
{
    /**
     * Disburse `amount` TSh to the creator's Tajiri Pay wallet.
     * Returns the transaction ID if disbursed, null if skipped (no wallet,
     * below threshold, etc.).
     */
    public static function disburse(int $userId, float $amount, string $reason = 'creator_earnings'): ?string
    {
        $minPayout = (float) config('earnings.min_payout_tsh', 5000);
        if ($amount < $minPayout) {
            Log::info("[Payout] Skipped — below threshold (user #{$userId}, TZS {$amount} < {$minPayout})");
            return null;
        }

        if (!Schema::hasTable('wallets') || !Schema::hasTable('wallet_transactions')) {
            Log::warning("[Payout] Tajiri Pay tables missing");
            return null;
        }

        return DB::transaction(function () use ($userId, $amount, $reason) {
            $wallet = DB::table('wallets')->where('user_id', $userId)->first();
            if (!$wallet) {
                Log::warning("[Payout] No wallet for user #{$userId}");
                return null;
            }

            $txId = 'EARN-' . strtoupper(uniqid());
            $balanceBefore = (float) $wallet->balance;
            $balanceAfter  = $balanceBefore + $amount;

            DB::table('wallet_transactions')->insert([
                'transaction_id' => $txId,
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => $reason,
                'amount'         => $amount,
                'fee'            => 0,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'status'         => 'completed',
                'payment_method' => 'wallet',
                'description'    => 'Creator earnings disbursement',
                'completed_at'   => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance'    => $balanceAfter,
                'updated_at' => now(),
            ]);

            return $txId;
        });
    }

    /**
     * Generate a digital tax invoice (TRA-format PDF stub) for a creator's
     * monthly earnings. Strategy §7.3.
     *
     * v1: returns a JSON document describing the invoice. v2 generates PDF
     * via dompdf or similar.
     */
    public static function generateTaxInvoice(int $userId, int $year, int $month): array
    {
        $start = now()->setDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->where('settlement_status', 'cleared')
            ->whereBetween('cleared_at', [$start, $end])
            ->selectRaw('SUM(net_to_creator) as net, SUM(tra_wht_held) as wht, COUNT(*) as cnt')
            ->first();

        $profile = DB::table('user_profiles')->where('id', $userId)->first();

        return [
            'invoice_number'   => "TAJIRI-{$year}{$month}-{$userId}",
            'issue_date'       => now()->toDateString(),
            'period'           => "{$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            'creator' => [
                'user_id'     => $userId,
                'name'        => $profile->full_name ?? null,
                'phone'       => $profile->phone_number ?? null,
                'tin'         => $profile->tin ?? null,
            ],
            'gross_paid_tsh'   => round((float) ($rows->net ?? 0) + (float) ($rows->wht ?? 0), 2),
            'net_paid_tsh'     => round((float) ($rows->net ?? 0), 2),
            'wht_withheld_tsh' => round((float) ($rows->wht ?? 0), 2),
            'wht_rate'         => '5%',
            'wht_section'      => 'Section 83B (Finance Act 2024)',
            'event_count'      => (int) ($rows->cnt ?? 0),
            'platform' => [
                'name'    => 'TAJIRI Limited',
                'tin'     => '123-456-789',
                'address' => 'Dar es Salaam, Tanzania',
            ],
        ];
    }
}
