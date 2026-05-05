<?php

namespace App\Services;

use App\Models\CreatorTier;
use Illuminate\Support\Facades\DB;

class MultiplierEngine
{
    /**
     * Compute all applicable multipliers for an event. Strategy §3 + §4.
     *
     * Context shape:
     *   target_user_id: int
     *   metric: string
     *   stream: string
     *   post_id: ?int
     *   watch_completion_pct: ?float (0.0–1.0; null for non-video)
     *   originality_flag: ?string ('original'|'derivative_substantial'|'derivative_minimal'|'reused')
     *   discovery_mode_active: bool
     *
     * @return array{watch_completion: ?float, originality: ?float, mwanzo_boost: ?float, streak: ?float, discovery_mode: ?float, tier_boost: ?float}
     */
    public static function compute(array $context): array
    {
        $tier = CreatorTier::forUser($context['target_user_id']);
        return [
            'watch_completion' => self::watchCompletion($context['watch_completion_pct'] ?? null, $context['metric']),
            'originality'      => self::originality($context['originality_flag'] ?? 'original'),
            'mwanzo_boost'     => self::mwanzoBoost($tier),
            'streak'           => self::streak($context['target_user_id']),
            'discovery_mode'   => self::discoveryMode($context['discovery_mode_active'] ?? false),
            'tier_boost'       => self::tierBoost($tier->tier),
        ];
    }

    public static function combined(array $multipliers): float
    {
        return array_reduce(
            $multipliers,
            fn ($acc, $m) => $acc * ($m === null ? 1.0 : (float) $m),
            1.0
        );
    }

    /** §3.1 — applies only to video metrics (view + watch_second). */
    public static function watchCompletion(?float $pct, string $metric): ?float
    {
        if ($pct === null || !in_array($metric, ['view', 'watch_second'], true)) {
            return null;
        }
        if ($pct < 0.25) return 0.5;
        if ($pct < 0.50) return 1.0;
        if ($pct < 0.70) return 1.5;
        if ($pct < 0.90) return 2.0;
        return 2.5;
    }

    /** §3.3 — originality. */
    public static function originality(string $flag): float
    {
        return match ($flag) {
            'original'                => 1.0,
            'derivative_substantial'  => 0.7,
            'derivative_minimal'      => 0.4,
            'reused'                  => 0.0,
            default                   => 1.0,
        };
    }

    /** §3.2 — Mwanzo Boost: 2× during first 30 days. */
    public static function mwanzoBoost(CreatorTier $tier): ?float
    {
        return $tier->isMwanzoActive() ? 2.0 : null;
    }

    /** §3.4 — +10% if posted ≥ 5 of last 7 days. */
    public static function streak(int $userId): ?float
    {
        $days = DB::table('posts')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as d')
            ->value('d');
        return ((int) $days >= 5) ? 1.10 : null;
    }

    /** §3.5 — Discovery Mode. */
    public static function discoveryMode(bool $active): ?float
    {
        return $active ? 0.70 : null;
    }

    /** §4 — Partner gets +5% on engagement pool only; others 1.00. */
    public static function tierBoost(string $tier): ?float
    {
        return $tier === 'partner' ? 1.05 : null;
    }
}
