<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class FeedCache extends Model
{
    use HasFactory;

    protected $table = 'feed_cache';

    protected $fillable = [
        'user_id',
        'feed_type',
        'post_ids',
        'page',
        'expires_at',
    ];

    protected $casts = [
        'post_ids' => 'array',
        'page' => 'integer',
        'expires_at' => 'datetime',
    ];

    const TYPE_FOR_YOU = 'for_you';
    const TYPE_FOLLOWING = 'following';
    const TYPE_TRENDING = 'trending';
    const TYPE_DISCOVER = 'discover';
    const TYPE_SHORTS = 'shorts';

    const CACHE_DURATION_MINUTES = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get cached feed or generate new one
     */
    public static function getCachedFeed(int $userId, string $feedType, int $page = 1): ?array
    {
        $cache = static::where('user_id', $userId)
            ->where('feed_type', $feedType)
            ->where('page', $page)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        return $cache?->post_ids;
    }

    /**
     * Store feed in cache
     */
    public static function storeFeed(int $userId, string $feedType, array $postIds, int $page = 1): self
    {
        return static::updateOrCreate(
            [
                'user_id' => $userId,
                'feed_type' => $feedType,
                'page' => $page,
            ],
            [
                'post_ids' => $postIds,
                'expires_at' => Carbon::now()->addMinutes(self::CACHE_DURATION_MINUTES),
            ]
        );
    }

    /**
     * Invalidate cache for a user
     */
    public static function invalidateForUser(int $userId, ?string $feedType = null): void
    {
        $query = static::where('user_id', $userId);

        if ($feedType) {
            $query->where('feed_type', $feedType);
        }

        $query->delete();
    }

    /**
     * Invalidate all expired caches
     */
    public static function clearExpired(): void
    {
        static::where('expires_at', '<', Carbon::now())->delete();
    }
}
