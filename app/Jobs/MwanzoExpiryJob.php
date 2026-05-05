<?php

namespace App\Jobs;

use App\Models\CreatorTier;
use App\Services\CreatorTierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Daily Mwanzo→Standard transition. At day 31, evaluates tier gates and
 * either promotes (delegating to CreatorTierService) or expires the
 * boost (Mwanzo multiplier returns null going forward).
 */
class MwanzoExpiryJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $expiring = CreatorTier::where('tier', 'mwanzo')
            ->whereNotNull('mwanzo_expires_at')
            ->where('mwanzo_expires_at', '<=', now())
            ->limit(2000)
            ->get();

        foreach ($expiring as $tier) {
            CreatorTierService::evaluate($tier->user_id);
        }

        Log::info("[MwanzoExpiry] Evaluated " . $expiring->count() . " creators at Mwanzo expiry");
    }
}
