<?php

namespace App\Services;

use App\Models\LiveStream;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis-based caching service for livestreaming.
 * Provides sub-millisecond access to stream metadata, viewer counts, and comments.
 */
class StreamCacheService
{
    // Cache TTLs (seconds)
    const TTL_METADATA = 300;      // 5 minutes
    const TTL_VIEWERS = 60;        // 1 minute
    const TTL_COMMENTS = 3600;     // 1 hour
    const TTL_SESSION = 3600;      // 1 hour
    const TTL_ACTIVE_STREAMS = 86400; // 24 hours

    // ==================== STREAM METADATA ====================

    /**
     * Cache stream metadata for fast retrieval.
     */
    public function cacheStreamMetadata(int $streamId, array $data): void
    {
        try {
            Redis::setex(
                "stream:meta:{$streamId}",
                self::TTL_METADATA,
                json_encode($data)
            );
        } catch (\Exception $e) {
            Log::warning("Redis cache failed for stream metadata: {$e->getMessage()}");
        }
    }

    /**
     * Get cached stream metadata.
     */
    public function getStreamMetadata(int $streamId): ?array
    {
        try {
            $cached = Redis::get("stream:meta:{$streamId}");
            return $cached ? json_decode($cached, true) : null;
        } catch (\Exception $e) {
            Log::warning("Redis read failed for stream metadata: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Invalidate stream metadata cache.
     */
    public function invalidateStreamMetadata(int $streamId): void
    {
        try {
            Redis::del("stream:meta:{$streamId}");
        } catch (\Exception $e) {
            Log::warning("Redis delete failed: {$e->getMessage()}");
        }
    }

    /**
     * Get stream with caching (read-through pattern).
     */
    public function getStream(int $streamId): ?array
    {
        // Try cache first
        $cached = $this->getStreamMetadata($streamId);
        if ($cached) {
            return $cached;
        }

        // Fallback to database
        $stream = LiveStream::with('user:id,first_name,last_name,username,profile_photo_path')
            ->find($streamId);

        if (!$stream) {
            return null;
        }

        $data = $stream->toArray();
        $this->cacheStreamMetadata($streamId, $data);

        return $data;
    }

    // ==================== VIEWER COUNT ====================

    /**
     * Increment viewer count atomically.
     */
    public function incrementViewerCount(int $streamId): int
    {
        try {
            $count = Redis::incr("stream:viewers:{$streamId}");
            Redis::expire("stream:viewers:{$streamId}", self::TTL_VIEWERS);
            return $count;
        } catch (\Exception $e) {
            Log::warning("Redis incr failed: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Decrement viewer count atomically.
     */
    public function decrementViewerCount(int $streamId): int
    {
        try {
            $count = Redis::decr("stream:viewers:{$streamId}");
            if ($count < 0) {
                Redis::set("stream:viewers:{$streamId}", 0);
                return 0;
            }
            return $count;
        } catch (\Exception $e) {
            Log::warning("Redis decr failed: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Get current viewer count.
     */
    public function getViewerCount(int $streamId): int
    {
        try {
            return max(0, (int) Redis::get("stream:viewers:{$streamId}"));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Set viewer count (sync from database).
     */
    public function setViewerCount(int $streamId, int $count): void
    {
        try {
            Redis::setex("stream:viewers:{$streamId}", self::TTL_VIEWERS, $count);
        } catch (\Exception $e) {
            Log::warning("Redis set viewer count failed: {$e->getMessage()}");
        }
    }

    // ==================== PEAK VIEWERS ====================

    /**
     * Update peak viewers if current exceeds it.
     */
    public function updatePeakViewers(int $streamId, int $currentViewers): int
    {
        try {
            $key = "stream:peak:{$streamId}";
            $peak = (int) Redis::get($key);

            if ($currentViewers > $peak) {
                Redis::setex($key, self::TTL_ACTIVE_STREAMS, $currentViewers);
                return $currentViewers;
            }

            return $peak;
        } catch (\Exception $e) {
            return $currentViewers;
        }
    }

    /**
     * Get peak viewers.
     */
    public function getPeakViewers(int $streamId): int
    {
        try {
            return (int) Redis::get("stream:peak:{$streamId}");
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== ACTIVE STREAMS ====================

    /**
     * Add stream to active streams set.
     */
    public function addActiveStream(int $streamId): void
    {
        try {
            Redis::sadd('streams:active', $streamId);
            Redis::expire('streams:active', self::TTL_ACTIVE_STREAMS);
        } catch (\Exception $e) {
            Log::warning("Redis sadd failed: {$e->getMessage()}");
        }
    }

    /**
     * Remove stream from active streams set.
     */
    public function removeActiveStream(int $streamId): void
    {
        try {
            Redis::srem('streams:active', $streamId);
        } catch (\Exception $e) {
            Log::warning("Redis srem failed: {$e->getMessage()}");
        }
    }

    /**
     * Get all active stream IDs.
     */
    public function getActiveStreamIds(): array
    {
        try {
            return Redis::smembers('streams:active') ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get count of active streams.
     */
    public function getActiveStreamCount(): int
    {
        try {
            return Redis::scard('streams:active') ?: 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== RECENT COMMENTS ====================

    /**
     * Cache a new comment (LIFO list).
     */
    public function cacheComment(int $streamId, array $comment): void
    {
        try {
            $key = "stream:comments:{$streamId}";
            Redis::lpush($key, json_encode($comment));
            Redis::ltrim($key, 0, 99); // Keep only last 100
            Redis::expire($key, self::TTL_COMMENTS);
        } catch (\Exception $e) {
            Log::warning("Redis cache comment failed: {$e->getMessage()}");
        }
    }

    /**
     * Get recent comments from cache.
     */
    public function getRecentComments(int $streamId, int $limit = 50): array
    {
        try {
            $comments = Redis::lrange("stream:comments:{$streamId}", 0, $limit - 1);
            return array_map(fn($c) => json_decode($c, true), $comments ?: []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear comments cache for a stream.
     */
    public function clearComments(int $streamId): void
    {
        try {
            Redis::del("stream:comments:{$streamId}");
        } catch (\Exception $e) {
            Log::warning("Redis clear comments failed: {$e->getMessage()}");
        }
    }

    // ==================== USER SESSIONS ====================

    /**
     * Cache user viewing session.
     */
    public function cacheUserSession(int $userId, int $streamId, array $data): void
    {
        try {
            Redis::setex(
                "session:{$userId}:{$streamId}",
                self::TTL_SESSION,
                json_encode($data)
            );
        } catch (\Exception $e) {
            Log::warning("Redis cache session failed: {$e->getMessage()}");
        }
    }

    /**
     * Get user viewing session.
     */
    public function getUserSession(int $userId, int $streamId): ?array
    {
        try {
            $cached = Redis::get("session:{$userId}:{$streamId}");
            return $cached ? json_decode($cached, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove user session.
     */
    public function removeUserSession(int $userId, int $streamId): void
    {
        try {
            Redis::del("session:{$userId}:{$streamId}");
        } catch (\Exception $e) {
            Log::warning("Redis remove session failed: {$e->getMessage()}");
        }
    }

    // ==================== STREAM HEALTH ====================

    /**
     * Cache stream health metrics.
     */
    public function cacheStreamHealth(int $streamId, array $health): void
    {
        try {
            Redis::setex(
                "stream:health:{$streamId}",
                60, // 1 minute
                json_encode($health)
            );
        } catch (\Exception $e) {
            Log::warning("Redis cache health failed: {$e->getMessage()}");
        }
    }

    /**
     * Get stream health metrics.
     */
    public function getStreamHealth(int $streamId): ?array
    {
        try {
            $cached = Redis::get("stream:health:{$streamId}");
            return $cached ? json_decode($cached, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==================== TOP STREAMS ====================

    /**
     * Cache top streams list.
     */
    public function cacheTopStreams(array $streams, int $ttl = 60): void
    {
        try {
            Cache::put('streams:top', $streams, $ttl);
        } catch (\Exception $e) {
            Log::warning("Cache top streams failed: {$e->getMessage()}");
        }
    }

    /**
     * Get cached top streams.
     */
    public function getTopStreams(): ?array
    {
        try {
            return Cache::get('streams:top');
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==================== BATTLE SCORES ====================

    /**
     * Update battle score atomically.
     */
    public function addBattleScore(int $battleId, int $streamId, int $points): int
    {
        try {
            $key = "battle:{$battleId}:score:{$streamId}";
            $newScore = Redis::incrby($key, $points);
            Redis::expire($key, 3600); // 1 hour
            return $newScore;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get battle score.
     */
    public function getBattleScore(int $battleId, int $streamId): int
    {
        try {
            return (int) Redis::get("battle:{$battleId}:score:{$streamId}");
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== RATE LIMITING ====================

    /**
     * Check if action is rate limited.
     */
    public function isRateLimited(string $action, int $userId, int $maxPerMinute): bool
    {
        try {
            $key = "rate:{$action}:{$userId}";
            $count = Redis::incr($key);

            if ($count === 1) {
                Redis::expire($key, 60);
            }

            return $count > $maxPerMinute;
        } catch (\Exception $e) {
            return false; // Allow on error
        }
    }

    // ==================== REACTION COUNTS ====================

    /**
     * Increment reaction count atomically.
     */
    public function incrementReactionCount(int $streamId, string $reactionType): int
    {
        try {
            $key = "stream:reactions:{$streamId}:{$reactionType}";
            $count = Redis::incr($key);
            Redis::expire($key, self::TTL_ACTIVE_STREAMS);
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get all reaction counts for a stream.
     */
    public function getReactionCounts(int $streamId): array
    {
        try {
            $types = ['like', 'love', 'fire', 'laugh', 'wow', 'sad'];
            $counts = [];

            foreach ($types as $type) {
                $counts[$type] = (int) Redis::get("stream:reactions:{$streamId}:{$type}") ?: 0;
            }

            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }

    // ==================== UNIQUE VIEWERS ====================

    /**
     * Track unique viewer (using HyperLogLog for memory efficiency).
     */
    public function trackUniqueViewer(int $streamId, int $userId): int
    {
        try {
            $key = "stream:unique_viewers:{$streamId}";
            Redis::pfadd($key, [(string) $userId]);
            Redis::expire($key, self::TTL_ACTIVE_STREAMS);
            return Redis::pfcount($key);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get unique viewer count.
     */
    public function getUniqueViewerCount(int $streamId): int
    {
        try {
            return Redis::pfcount("stream:unique_viewers:{$streamId}") ?: 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== GIFTS/SUPER CHAT QUEUE ====================

    /**
     * Add gift to processing queue.
     */
    public function queueGift(int $streamId, array $giftData): void
    {
        try {
            Redis::rpush("stream:gifts_queue:{$streamId}", json_encode($giftData));
            Redis::expire("stream:gifts_queue:{$streamId}", 3600);
        } catch (\Exception $e) {
            Log::warning("Redis queue gift failed: {$e->getMessage()}");
        }
    }

    /**
     * Get pending gifts from queue.
     */
    public function dequeuGifts(int $streamId, int $count = 10): array
    {
        try {
            $gifts = [];
            for ($i = 0; $i < $count; $i++) {
                $gift = Redis::lpop("stream:gifts_queue:{$streamId}");
                if ($gift) {
                    $gifts[] = json_decode($gift, true);
                } else {
                    break;
                }
            }
            return $gifts;
        } catch (\Exception $e) {
            return [];
        }
    }

    // ==================== STREAM LATENCY TRACKING ====================

    /**
     * Record stream latency sample.
     */
    public function recordLatency(int $streamId, float $latencyMs): void
    {
        try {
            $key = "stream:latency:{$streamId}";
            Redis::lpush($key, $latencyMs);
            Redis::ltrim($key, 0, 59); // Keep last 60 samples
            Redis::expire($key, 300);
        } catch (\Exception $e) {
            // Silently fail for latency tracking
        }
    }

    /**
     * Get average latency for stream.
     */
    public function getAverageLatency(int $streamId): float
    {
        try {
            $samples = Redis::lrange("stream:latency:{$streamId}", 0, -1);
            if (empty($samples)) {
                return 0;
            }
            return array_sum(array_map('floatval', $samples)) / count($samples);
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * Get multiple stream viewer counts efficiently.
     */
    public function getMultipleViewerCounts(array $streamIds): array
    {
        try {
            $keys = array_map(fn($id) => "stream:viewers:{$id}", $streamIds);
            $values = Redis::mget($keys);

            $result = [];
            foreach ($streamIds as $i => $id) {
                $result[$id] = max(0, (int) ($values[$i] ?? 0));
            }
            return $result;
        } catch (\Exception $e) {
            return array_fill_keys($streamIds, 0);
        }
    }

    /**
     * Pipeline multiple operations for efficiency.
     */
    public function updateStreamStats(int $streamId, array $stats): void
    {
        try {
            Redis::pipeline(function ($pipe) use ($streamId, $stats) {
                if (isset($stats['viewers'])) {
                    $pipe->set("stream:viewers:{$streamId}", $stats['viewers']);
                    $pipe->expire("stream:viewers:{$streamId}", self::TTL_VIEWERS);
                }
                if (isset($stats['likes'])) {
                    $pipe->set("stream:reactions:{$streamId}:like", $stats['likes']);
                }
                if (isset($stats['peak'])) {
                    $pipe->set("stream:peak:{$streamId}", $stats['peak']);
                }
            });
        } catch (\Exception $e) {
            Log::warning("Redis pipeline failed: {$e->getMessage()}");
        }
    }
}
