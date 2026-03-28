<?php

namespace App\Services\ContentEngine;

use Illuminate\Support\Facades\Redis;

class FeedCacheService
{
    /**
     * Get cached feed page.
     * @return array|null Cached results or null if miss
     */
    public static function getFeed(int $userId, string $feedType, int $perPage, int $page): ?array
    {
        $key = self::feedKey($userId, $feedType, $perPage, $page);
        $cached = Redis::get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache a feed page.
     */
    public static function setFeed(int $userId, string $feedType, int $perPage, int $page, array $data): void
    {
        $key = self::feedKey($userId, $feedType, $perPage, $page);
        $ttl = config('content-engine.serving.cache.feed_ttl', 60);
        Redis::setex($key, $ttl, json_encode($data));
    }

    /**
     * Invalidate all cached pages for a user's feed type.
     */
    public static function invalidateFeed(int $userId, string $feedType): void
    {
        // Use Redis SCAN to find and delete all matching keys for this user+feedType
        $pattern = "feed:{$userId}:{$feedType}:*";
        $cursor = '0';
        do {
            [$cursor, $keys] = Redis::scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if (!empty($keys)) {
                Redis::del(...$keys);
            }
        } while ($cursor !== '0');
    }

    /**
     * Get cached search results (per-user due to personalized scoring).
     */
    public static function getSearch(int $userId, string $query, array $filters, int $page): ?array
    {
        $key = self::searchKey($userId, $query, $filters, $page);
        $cached = Redis::get($key);
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Cache search results (per-user due to personalized scoring).
     */
    public static function setSearch(int $userId, string $query, array $filters, int $page, array $data): void
    {
        $key = self::searchKey($userId, $query, $filters, $page);
        $ttl = config('content-engine.serving.cache.search_ttl', 300);
        Redis::setex($key, $ttl, json_encode($data));
    }

    private static function feedKey(int $userId, string $feedType, int $perPage, int $page): string
    {
        return "feed:{$userId}:{$feedType}:{$perPage}:page:{$page}";
    }

    private static function searchKey(int $userId, string $query, array $filters, int $page): string
    {
        $queryHash = md5($query);
        $filtersHash = md5(json_encode($filters));
        return "search:{$userId}:{$queryHash}:{$filtersHash}:page:{$page}";
    }
}
