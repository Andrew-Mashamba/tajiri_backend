<?php

namespace App\Services;

use App\Models\CreatorTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Promotes / demotes / pauses creators per strategy §4 tier gates and
 * the §8 inactivity rule. Auto-promotion only — demotions are manual
 * (admin-driven) so creators never lose tier without review.
 */
class CreatorTierService
{
    /**
     * Re-evaluate a creator's tier per strategy §4 gates.
     */
    public static function evaluate(int $userId): void
    {
        $tier = CreatorTier::forUser($userId);
        $followers = (int) DB::table('user_follows')->where('following_id', $userId)->count();
        $views30d  = (int) DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->where('metric', 'view')
            ->where('is_chargeable', true)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->sum('raw_count');
        $strikes90d = (int) $tier->strike_count;
        $daysActive = (int) DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->selectRaw('COUNT(DISTINCT DATE(occurred_at)) as d')
            ->value('d');

        $newTier = $tier->tier;
        if ($tier->tier === 'mwanzo' && $followers >= 100 && $daysActive >= 30 && $strikes90d === 0) {
            $newTier = 'standard';
        } elseif ($tier->tier === 'standard'
            && $followers >= 1000 && $views30d >= 50_000
            && $tier->is_id_verified && $strikes90d === 0) {
            $newTier = 'verified';
        } elseif ($tier->tier === 'verified'
            && $followers >= 10_000 && $views30d >= 500_000
            && $tier->promoted_at <= now()->subDays(90)) {
            // Partner is gated on manual review — flag for admin.
            $tier->update(['next_review_at' => now()->subSecond()]);
            return;
        }

        if ($newTier !== $tier->tier) {
            self::promote($userId, $newTier);
        } else {
            $tier->update(['next_review_at' => now()->addDays(7)]);
        }
    }

    public static function promote(int $userId, string $newTier): void
    {
        $tier = CreatorTier::forUser($userId);
        $tier->update([
            'tier'           => $newTier,
            'promoted_at'    => now(),
            'next_review_at' => now()->addDays(7),
        ]);
        Log::info("[CreatorTier] Promoted user #{$userId} to {$newTier}");
    }

    public static function demote(int $userId, string $newTier, string $reason): void
    {
        $tier = CreatorTier::forUser($userId);
        $tier->update(['tier' => $newTier, 'promoted_at' => now()]);
        Log::warning("[CreatorTier] Demoted user #{$userId} to {$newTier}: {$reason}");
    }

    /** §8 — pause monetization after 90 days of inactivity. */
    public static function checkInactivity(int $userId): void
    {
        $tier = CreatorTier::forUser($userId);
        $lastEvent = DB::table('earning_events')
            ->where('target_user_id', $userId)
            ->max('occurred_at');
        $lastPost = DB::table('posts')
            ->where('user_id', $userId)
            ->max('created_at');
        $latest = max($lastEvent, $lastPost);

        if ($latest && $latest <= now()->subDays(90)->toDateTimeString() && !$tier->monetization_paused) {
            $tier->update(['monetization_paused' => true]);
            Log::info("[CreatorTier] Monetization paused for user #{$userId} (90d inactive)");
        }
    }
}
