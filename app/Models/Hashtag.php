<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class Hashtag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_normalized',
        'posts_count',
        'usage_count_24h',
        'usage_count_7d',
        'is_trending',
        'is_blocked',
        'category',
    ];

    protected $casts = [
        'posts_count' => 'integer',
        'usage_count_24h' => 'integer',
        'usage_count_7d' => 'integer',
        'is_trending' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_hashtags')->withTimestamps();
    }

    /**
     * Get trending hashtags
     */
    public static function trending(int $limit = 20)
    {
        return static::query()
            ->where('is_blocked', false)
            ->orderByDesc('usage_count_24h')
            ->limit($limit)
            ->get();
    }

    /**
     * Update trending status for all hashtags
     */
    public static function refreshTrendingStatus(): void
    {
        // Mark top 50 as trending
        $trendingIds = static::query()
            ->where('is_blocked', false)
            ->where('usage_count_24h', '>', 0)
            ->orderByDesc('usage_count_24h')
            ->limit(50)
            ->pluck('id');

        static::query()->update(['is_trending' => false]);
        static::whereIn('id', $trendingIds)->update(['is_trending' => true]);
    }

    /**
     * Reset 24h counts (run daily via scheduler)
     */
    public static function resetDailyCounts(): void
    {
        static::query()->update(['usage_count_24h' => 0]);
    }

    /**
     * Reset 7d counts (run weekly via scheduler)
     */
    public static function resetWeeklyCounts(): void
    {
        static::query()->update(['usage_count_7d' => 0]);
    }

    /**
     * Search hashtags by name
     */
    public static function search(string $query, int $limit = 10)
    {
        $normalized = strtolower($query);

        return static::query()
            ->where('is_blocked', false)
            ->where('name_normalized', 'like', $normalized . '%')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();
    }
}
