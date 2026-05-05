<?php

namespace App\Jobs;

use App\Models\CreatorTier;
use App\Services\CreatorTierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class TierReviewJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $candidates = CreatorTier::whereIn('tier', ['standard', 'verified'])
            ->where(function ($q) {
                $q->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
            })
            ->pluck('user_id');

        foreach ($candidates as $userId) {
            CreatorTierService::evaluate((int) $userId);
            CreatorTierService::checkInactivity((int) $userId);
        }
        Log::info("[TierReview] Reviewed " . count($candidates) . " creators.");
    }
}
