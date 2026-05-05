<?php

namespace App\Services\ContentEngine;

use Illuminate\Support\Facades\Redis;

class SignalService
{
    public static function docKey(string $sourceType, int $sourceId): string
    {
        return "signals:{$sourceType}:{$sourceId}";
    }

    public static function classifyView(int $durationMs): string
    {
        if ($durationMs < 500) return 'scroll_past';
        if ($durationMs < 2000) return 'view_short';
        if ($durationMs < 5000) return 'view_glance';
        if ($durationMs < 15000) return 'view_partial';
        return 'view_deep';
    }

    public static function getWeight(string $eventType, int $durationMs = 0): float
    {
        $weights = config('content-engine.signal_weights');

        if ($eventType === 'view' || $eventType === 'dwell' || $eventType === 'scroll_past') {
            $classified = self::classifyView($durationMs);
            return $weights[$classified] ?? 0.1;
        }

        return $weights[$eventType] ?? 0.1;
    }

    /**
     * Increment document counters in Redis and recompute engagement_score.
     * Enforces all 4 anti-gaming rules.
     * Returns the effective weight applied (0 if blocked).
     */
    public static function incrementSignal(
        string $sourceType,
        int $sourceId,
        string $eventType,
        int $durationMs,
        int $userId,
        ?string $ipAddress = null
    ): float {
        $weight = self::getWeight($eventType, $durationMs);

        // === Anti-Gaming Rule 1: Per-user cap (1 signal per type per doc per hour) ===
        $capKey = "signal_cap:{$userId}:{$sourceType}:{$sourceId}:{$eventType}";
        $cap = config('content-engine.anti_gaming.per_user_cap_per_hour', 1);
        if (Redis::exists($capKey) && (int) Redis::get($capKey) >= $cap) {
            return 0;
        }
        $isNew = Redis::setnx($capKey, 0);
        if ($isNew) {
            Redis::expire($capKey, 3600);
        }
        Redis::incr($capKey);

        // === Anti-Gaming Rule 3: Social graph validation ===
        $socialWeight = self::getSocialGraphWeight($userId);
        $weight *= $socialWeight;

        // === Anti-Gaming Rule 4: IP clustering ===
        if ($ipAddress) {
            $ipKey = "ip_cluster:{$ipAddress}:{$sourceType}:{$sourceId}";
            $ipCount = (int) Redis::incr($ipKey);
            if ($ipCount === 1) Redis::expire($ipKey, 300);
            $ipLimit = config('content-engine.anti_gaming.ip_cluster_threshold', 10);
            $ipCountLimit = config('content-engine.anti_gaming.ip_cluster_count_limit', 3);
            if ($ipCount > $ipLimit) {
                if ($ipCount > $ipLimit + $ipCountLimit) {
                    return 0;
                }
            }
        }

        $docKey = self::docKey($sourceType, $sourceId);

        $counterField = self::mapEventToCounter($eventType);
        if ($counterField) {
            Redis::hIncrBy($docKey, $counterField, 1);
        }

        if (in_array($eventType, ['view', 'dwell']) && $durationMs > 0) {
            Redis::hIncrBy($docKey, 'total_dwell_ms', $durationMs);
        }

        $fiveMinKey = "signals_5min:{$sourceType}:{$sourceId}";
        Redis::hIncrByFloat($fiveMinKey, 'weighted_sum', $weight);
        Redis::hIncrBy($fiveMinKey, 'count', 1);
        Redis::expire($fiveMinKey, 600);

        // === Anti-Gaming Rule 2: Velocity squashing for new accounts ===
        $newAccountDays = config('content-engine.anti_gaming.new_account_days', 7);
        $fraudThreshold = config('content-engine.anti_gaming.velocity_fraud_threshold', 100);
        $newAcctKey = "new_acct_engagements:{$sourceType}:{$sourceId}";
        if (self::isNewAccount($userId, $newAccountDays)) {
            $newAcctCount = (int) Redis::incr($newAcctKey);
            if ($newAcctCount === 1) Redis::expire($newAcctKey, 300);
            if ($newAcctCount > $fraudThreshold) {
                Redis::hSet($docKey, 'fraud_flagged', '1');
            }
        }

        self::recomputeEngagementScore($sourceType, $sourceId);

        // Mark dirty for PG sync AND trending candidates
        $dirtyKey = config('content-engine.score_sync.dirty_set_key', 'scores:dirty');
        $member = "{$sourceType}:{$sourceId}";
        Redis::sAdd($dirtyKey, $member);
        Redis::sAdd('trending:candidates', $member);

        Redis::expire($docKey, 2592000); // 30 days

        return $weight;
    }

    private static function getSocialGraphWeight(int $userId): float
    {
        $cacheKey = "social_weight:{$userId}";
        $cached = Redis::get($cacheKey);
        if ($cached !== null) return (float) $cached;

        $friendCount = \Illuminate\Support\Facades\DB::table('friendships')
            ->where('user_id', $userId)
            ->orWhere('friend_id', $userId)
            ->limit(1)->count();
        $postCount = \Illuminate\Support\Facades\DB::table('posts')
            ->where('user_id', $userId)
            ->limit(1)->count();

        $weight = ($friendCount === 0 && $postCount === 0)
            ? config('content-engine.anti_gaming.zero_social_weight', 0.1)
            : 1.0;

        Redis::setex($cacheKey, 3600, $weight);
        return $weight;
    }

    private static function isNewAccount(int $userId, int $days): bool
    {
        $cacheKey = "new_account:{$userId}";
        $cached = Redis::get($cacheKey);
        if ($cached !== null) return $cached === '1';

        $user = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $userId)
            ->value('created_at');

        $isNew = $user && \Carbon\Carbon::parse($user)->gt(now()->subDays($days));
        Redis::setex($cacheKey, 86400, $isNew ? '1' : '0');
        return $isNew;
    }

    private static function mapEventToCounter(string $eventType): ?string
    {
        return match ($eventType) {
            'view', 'dwell' => 'views',
            'like' => 'likes',
            'comment' => 'comments',
            'share' => 'shares',
            'save' => 'saves',
            'reply' => 'replies',
            default => null,
        };
    }

    public static function recomputeEngagementScore(string $sourceType, int $sourceId): float
    {
        $docKey = self::docKey($sourceType, $sourceId);
        $data = Redis::hGetAll($docKey);

        $views = (int) ($data['views'] ?? 0);
        $likes = (int) ($data['likes'] ?? 0);
        $comments = (int) ($data['comments'] ?? 0);
        $shares = (int) ($data['shares'] ?? 0);
        $saves = (int) ($data['saves'] ?? 0);
        $replies = (int) ($data['replies'] ?? 0);
        $totalDwellMs = (int) ($data['total_dwell_ms'] ?? 0);
        $avgDwellSec = $views > 0 ? ($totalDwellMs / $views / 1000) : 0;

        $raw = $views * 0.1
            + $likes * 1.0
            + $comments * 2.0
            + $shares * 2.5
            + $saves * 1.8
            + $replies * 3.0
            + $avgDwellSec * 0.05;

        $k = config('content-engine.scoring.engagement_normalization_k', 50);
        $score = 100 * (1 - exp(-$raw / $k));

        Redis::hSet($docKey, 'engagement_score', round($score, 2));

        return $score;
    }

    public static function getEngagementScore(string $sourceType, int $sourceId): float
    {
        return (float) (Redis::hGet(self::docKey($sourceType, $sourceId), 'engagement_score') ?? 0);
    }

    public static function getCounters(string $sourceType, int $sourceId): array
    {
        return Redis::hGetAll(self::docKey($sourceType, $sourceId)) ?: [];
    }
}
