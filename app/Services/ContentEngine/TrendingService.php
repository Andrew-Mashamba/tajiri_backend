<?php

namespace App\Services\ContentEngine;

use Illuminate\Support\Facades\Redis;

class TrendingService
{
    public static function computeTrendingScore(string $sourceType, int $sourceId): float
    {
        $fiveMinKey = "signals_5min:{$sourceType}:{$sourceId}";
        $current5min = (int) (Redis::hGet($fiveMinKey, 'count') ?? 0);

        $docKey = SignalService::docKey($sourceType, $sourceId);
        $avg5min24h = (float) (Redis::hGet($docKey, 'avg_5min_24h') ?? 1);
        if ($avg5min24h < 1) $avg5min24h = 1;

        $multiplier = config('content-engine.scoring.trending_velocity_multiplier', 20);
        $velocity = $current5min / $avg5min24h;
        $score = min(100, $velocity * $multiplier);

        return round($score, 2);
    }

    public static function updateTrendingSets(
        string $sourceType,
        int $sourceId,
        float $trendingScore,
        ?string $region = null,
        ?string $category = null,
        ?array $hashtags = []
    ): void {
        $member = "{$sourceType}:{$sourceId}";

        Redis::zAdd('trending:global', $trendingScore, $member);

        if ($region) {
            Redis::zAdd("trending:region:{$region}", $trendingScore, $member);
            Redis::expire("trending:region:{$region}", 86400);
        }

        if ($category) {
            Redis::zAdd("trending:category:{$category}", $trendingScore, $member);
            Redis::expire("trending:category:{$category}", 86400);
        }

        if (!empty($hashtags)) {
            foreach (array_slice($hashtags, 0, 5) as $tag) {
                Redis::zAdd("trending:hashtag:{$tag}", $trendingScore, $member);
                Redis::expire("trending:hashtag:{$tag}", 86400);
            }
        }
    }

    public static function getTopTrending(int $limit = 50): array
    {
        return Redis::zRevRange('trending:global', 0, $limit - 1, 'WITHSCORES') ?: [];
    }

    public static function getRegionTrending(string $region, int $limit = 50): array
    {
        return Redis::zRevRange("trending:region:{$region}", 0, $limit - 1, 'WITHSCORES') ?: [];
    }

    public static function classifyTrending(float $velocity): string
    {
        $rising = config('content-engine.scoring.trending_rising_threshold', 3);
        $breaking = config('content-engine.scoring.trending_breaking_threshold', 10);

        if ($velocity >= $breaking) return 'breaking';
        if ($velocity >= $rising) return 'rising';
        if ($velocity < 0.5) return 'cooling';
        return 'stable';
    }

    public static function pruneGlobalTrending(float $minScore = 1.0): int
    {
        return Redis::zRemRangeByScore('trending:global', '-inf', '(' . $minScore) ?: 0;
    }
}
