<?php

namespace App\Services\ContentEngine;

use Illuminate\Support\Facades\Redis;

class UserSignalService
{
    public static function userKey(int $userId): string
    {
        return "user:{$userId}:signals";
    }

    public static function updateProfile(
        int $userId,
        string $eventType,
        ?int $creatorId,
        ?string $category,
        ?array $hashtags,
        ?array $mediaTypes,
        int $durationMs = 0
    ): void {
        if (in_array($eventType, ['scroll_past', 'not_interested'])) {
            return;
        }

        $key = self::userKey($userId);

        if ($creatorId && in_array($eventType, ['like', 'comment', 'share', 'save', 'follow'])) {
            self::addToJsonSet($key, 'liked_creators', (string) $creatorId, 50);
        }

        if ($category && in_array($eventType, ['like', 'comment', 'share', 'save', 'view'])) {
            self::addToJsonSet($key, 'liked_categories', $category, 20);
        }

        if (!empty($hashtags)) {
            foreach (array_slice($hashtags, 0, 5) as $tag) {
                self::addToJsonSet($key, 'liked_hashtags', $tag, 30);
            }
        }

        if (!empty($mediaTypes)) {
            foreach ($mediaTypes as $mt) {
                if ($mt) self::addToJsonSet($key, 'preferred_media', $mt, 5);
            }
        }

        if ($durationMs > 0 && in_array($eventType, ['view', 'dwell'])) {
            $currentAvg = (int) (Redis::hGet($key, 'avg_dwell_ms') ?? 0);
            $newAvg = $currentAvg > 0 ? (int) (($currentAvg * 0.9) + ($durationMs * 0.1)) : $durationMs;
            Redis::hSet($key, 'avg_dwell_ms', $newAvg);
        }

        $hour = (int) date('G');
        self::addToJsonSet($key, 'active_hours', (string) $hour, 24);

        Redis::hSet($key, 'last_updated', now()->toIso8601String());
        Redis::expire($key, 2592000); // 30 day TTL
    }

    public static function getProfile(int $userId): array
    {
        $key = self::userKey($userId);
        $data = Redis::hGetAll($key);

        if (empty($data)) {
            return [
                'liked_creators' => [],
                'liked_categories' => [],
                'liked_hashtags' => [],
                'preferred_media' => [],
                'active_hours' => [],
                'avg_dwell_ms' => 0,
                'last_updated' => null,
            ];
        }

        return [
            'liked_creators' => json_decode($data['liked_creators'] ?? '[]', true) ?: [],
            'liked_categories' => json_decode($data['liked_categories'] ?? '[]', true) ?: [],
            'liked_hashtags' => json_decode($data['liked_hashtags'] ?? '[]', true) ?: [],
            'preferred_media' => json_decode($data['preferred_media'] ?? '[]', true) ?: [],
            'active_hours' => json_decode($data['active_hours'] ?? '[]', true) ?: [],
            'avg_dwell_ms' => (int) ($data['avg_dwell_ms'] ?? 0),
            'last_updated' => $data['last_updated'] ?? null,
        ];
    }

    private static function addToJsonSet(string $hashKey, string $field, string $value, int $maxSize): void
    {
        $current = json_decode(Redis::hGet($hashKey, $field) ?? '[]', true) ?: [];
        $current = array_values(array_filter($current, fn($v) => $v !== $value));
        $current[] = $value;

        if (count($current) > $maxSize) {
            $current = array_slice($current, -$maxSize);
        }

        Redis::hSet($hashKey, $field, json_encode($current));
    }
}
