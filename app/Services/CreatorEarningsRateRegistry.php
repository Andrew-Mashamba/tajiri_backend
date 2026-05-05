<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreatorEarningsRateRegistry
{
    public const CACHE_KEY = 'earnings:rates:active:v1';
    public const CACHE_TTL = 300; // 5 min

    /**
     * Look up the active rate for a (metric, actor_role, stream) tuple.
     * Returns null if no row matches or if the creator's tier is below tier_minimum.
     *
     * @return array{rate: float, max_cap_tsh: ?float, tier_minimum: ?string}|null
     */
    public static function rateFor(string $metric, string $actorRole, string $stream, string $creatorTier): ?array
    {
        $rates = self::activeRates();
        $key = "{$metric}|{$actorRole}|{$stream}";
        $row = $rates[$key] ?? null;
        if (!$row) {
            return null;
        }

        // Tier gate per strategy §4.
        $tierMin = $row['tier_minimum'] ?? 'mwanzo';
        $rank = ['mwanzo' => 0, 'standard' => 1, 'verified' => 2, 'partner' => 3];
        if (($rank[$creatorTier] ?? -1) < ($rank[$tierMin] ?? 0)) {
            return null;
        }

        return [
            'rate'         => (float) $row['rate'],
            'max_cap_tsh'  => $row['max_cap_tsh'] !== null ? (float) $row['max_cap_tsh'] : null,
            'tier_minimum' => $row['tier_minimum'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function activeRates(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $rows = DB::table('creator_earnings_rates')
                ->where('is_active', true)
                ->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->get();
            $out = [];
            foreach ($rows as $r) {
                $out["{$r->metric}|{$r->actor_role}|{$r->stream}"] = (array) $r;
            }
            return $out;
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
