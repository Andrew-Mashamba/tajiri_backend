<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Hybrid Post Model
 *
 * Supports multiple content types with engagement-based ranking
 * inspired by TikTok, Instagram, YouTube Shorts, and Twitter.
 */
class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'content',
        'post_type',
        'privacy',
        'location_name',
        'latitude',
        'longitude',
        'region_id',
        'tagged_users',
        'likes_count',
        'comments_count',
        'shares_count',
        'views_count',
        'impressions_count',
        'watch_time_seconds',
        'saves_count',
        'replies_count',
        'engagement_score',
        'trending_score',
        'content_category',
        'content_tags',
        'language_code',
        'is_short_video',
        'is_featured',
        'is_viral',
        'reach_followers',
        'reach_non_followers',
        'is_pinned',
        'status',
        'scheduled_at',
        'published_at',
        'draft_id',
        'is_draft',
        'original_post_id',
        // New audio/video fields
        'background_color',
        'audio_path',
        'audio_duration',
        'audio_waveform',
        'cover_image_path',
        'music_track_id',
        'music_start_time',
        'original_audio_volume',
        'music_volume',
        'video_speed',
        'text_overlays',
        'video_filter',
    ];

    protected $casts = [
        'tagged_users' => 'array',
        'content_tags' => 'array',
        'audio_waveform' => 'array',
        'text_overlays' => 'array',
        'is_pinned' => 'boolean',
        'is_short_video' => 'boolean',
        'is_featured' => 'boolean',
        'is_viral' => 'boolean',
        'is_draft' => 'boolean',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'shares_count' => 'integer',
        'views_count' => 'integer',
        'impressions_count' => 'integer',
        'watch_time_seconds' => 'integer',
        'saves_count' => 'integer',
        'replies_count' => 'integer',
        'reach_followers' => 'integer',
        'reach_non_followers' => 'integer',
        'audio_duration' => 'integer',
        'music_start_time' => 'integer',
        'engagement_score' => 'decimal:4',
        'trending_score' => 'decimal:4',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'original_audio_volume' => 'decimal:2',
        'music_volume' => 'decimal:2',
        'video_speed' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * Post status constants
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';

    /**
     * Post types
     */
    const TYPE_TEXT = 'text';
    const TYPE_PHOTO = 'photo';
    const TYPE_VIDEO = 'video';
    const TYPE_SHORT_VIDEO = 'short_video';
    const TYPE_AUDIO = 'audio';
    const TYPE_AUDIO_TEXT = 'audio_text';
    const TYPE_IMAGE_TEXT = 'image_text';
    const TYPE_POLL = 'poll';
    const TYPE_SHARED = 'shared';

    /**
     * All valid post types
     */
    const POST_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_PHOTO,
        self::TYPE_VIDEO,
        self::TYPE_SHORT_VIDEO,
        self::TYPE_AUDIO,
        self::TYPE_AUDIO_TEXT,
        self::TYPE_IMAGE_TEXT,
        self::TYPE_POLL,
        self::TYPE_SHARED,
    ];

    /**
     * Privacy levels
     */
    const PRIVACY_PUBLIC = 'public';
    const PRIVACY_FRIENDS = 'friends';
    const PRIVACY_PRIVATE = 'private';

    /**
     * Engagement weights (Twitter-inspired: replies > shares > likes)
     * TikTok-inspired: watch time is king for videos
     */
    const WEIGHT_REPLY = 3.0;      // Replies show deep engagement
    const WEIGHT_SHARE = 2.5;      // Shares extend reach
    const WEIGHT_COMMENT = 2.0;    // Comments show interest
    const WEIGHT_SAVE = 1.8;       // Saves show value
    const WEIGHT_LIKE = 1.0;       // Likes are baseline
    const WEIGHT_VIEW = 0.1;       // Views are passive
    const WEIGHT_WATCH_TIME = 0.5; // Per second of watch time (video)

    /**
     * Media type boost (Instagram-inspired: video > images > text)
     */
    const BOOST_VIDEO = 1.5;
    const BOOST_SHORT_VIDEO = 2.0; // Shorts get extra boost
    const BOOST_IMAGE = 1.2;
    const BOOST_TEXT = 1.0;

    /**
     * Time decay constants (Twitter-inspired: freshness matters)
     */
    const DECAY_HALF_LIFE_HOURS = 6; // Score halves every 6 hours

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('order');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->whereNull('parent_id')->orderBy('created_at', 'desc');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at', 'desc');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function originalPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'original_post_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Post::class, 'original_post_id');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'post_hashtags')->withTimestamps();
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostView::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function musicTrack(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class, 'music_track_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PostDraft::class, 'draft_id');
    }

    public function taggedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_tagged_users')->withTimestamps();
    }

    // ==================== ENGAGEMENT CHECKS ====================

    public function isLikedBy(int $userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function isSavedBy(int $userId): bool
    {
        return $this->saves()->where('user_id', $userId)->exists();
    }

    public function getReactionBy(int $userId): ?string
    {
        $like = $this->likes()->where('user_id', $userId)->first();
        return $like?->reaction_type;
    }

    // ==================== COUNTER METHODS ====================

    public function incrementLikes(): void
    {
        $this->increment('likes_count');
        $this->recalculateEngagementScore();
    }

    public function decrementLikes(): void
    {
        $this->decrement('likes_count');
        $this->recalculateEngagementScore();
    }

    public function incrementComments(): void
    {
        $this->increment('comments_count');
        $this->recalculateEngagementScore();
    }

    public function decrementComments(): void
    {
        $this->decrement('comments_count');
        $this->recalculateEngagementScore();
    }

    public function incrementShares(): void
    {
        $this->increment('shares_count');
        $this->recalculateEngagementScore();
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementImpressions(): void
    {
        $this->increment('impressions_count');
    }

    public function incrementSaves(): void
    {
        $this->increment('saves_count');
        $this->recalculateEngagementScore();
    }

    public function decrementSaves(): void
    {
        $this->decrement('saves_count');
        $this->recalculateEngagementScore();
    }

    public function addWatchTime(int $seconds): void
    {
        $this->increment('watch_time_seconds', $seconds);
        $this->recalculateEngagementScore();
    }

    // ==================== ENGAGEMENT SCORING ====================

    /**
     * Calculate engagement score
     *
     * Formula inspired by:
     * - Twitter: Weighted engagement (replies > shares > likes)
     * - TikTok: Watch time as primary signal for videos
     * - Instagram: Reach-adjusted engagement rate
     */
    public function recalculateEngagementScore(): void
    {
        // Base engagement score
        $rawScore = ($this->replies_count * self::WEIGHT_REPLY)
            + ($this->shares_count * self::WEIGHT_SHARE)
            + ($this->comments_count * self::WEIGHT_COMMENT)
            + ($this->saves_count * self::WEIGHT_SAVE)
            + ($this->likes_count * self::WEIGHT_LIKE)
            + ($this->views_count * self::WEIGHT_VIEW);

        // Add watch time bonus for video posts (TikTok-style)
        if ($this->post_type === self::TYPE_VIDEO && $this->views_count > 0) {
            $avgWatchTime = $this->watch_time_seconds / max($this->views_count, 1);
            $rawScore += $avgWatchTime * self::WEIGHT_WATCH_TIME;
        }

        // Apply media type boost (Instagram-style)
        $mediaBoost = $this->getMediaBoost();
        $rawScore *= $mediaBoost;

        // Apply time decay (Twitter-style freshness)
        $hoursOld = Carbon::now()->diffInHours($this->created_at);
        $decayFactor = pow(0.5, $hoursOld / self::DECAY_HALF_LIFE_HOURS);
        $decayedScore = $rawScore * $decayFactor;

        // Calculate trending score (recent engagement weighted higher)
        $trendingScore = $this->calculateTrendingScore();

        $this->update([
            'engagement_score' => round($decayedScore, 4),
            'trending_score' => round($trendingScore, 4),
            'is_viral' => $this->checkViralThreshold($rawScore),
        ]);
    }

    /**
     * Get media type boost multiplier
     */
    protected function getMediaBoost(): float
    {
        if ($this->is_short_video) {
            return self::BOOST_SHORT_VIDEO;
        }

        return match ($this->post_type) {
            self::TYPE_VIDEO => self::BOOST_VIDEO,
            self::TYPE_PHOTO => self::BOOST_IMAGE,
            default => self::BOOST_TEXT,
        };
    }

    /**
     * Calculate trending score (velocity of engagement)
     * Higher score = faster recent engagement growth
     */
    protected function calculateTrendingScore(): float
    {
        // Get engagement from last 24 hours
        $recentViews = $this->views()
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        $recentLikes = $this->likes()
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        $recentComments = $this->allComments()
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        // Weight recent activity
        $recentScore = ($recentLikes * self::WEIGHT_LIKE)
            + ($recentComments * self::WEIGHT_COMMENT)
            + ($recentViews * self::WEIGHT_VIEW);

        // Normalize by post age (newer posts get slight advantage)
        $hoursOld = max(Carbon::now()->diffInHours($this->created_at), 1);
        $ageNormalizer = min(24 / $hoursOld, 2.0); // Cap at 2x for very new posts

        return $recentScore * $ageNormalizer;
    }

    /**
     * Check if post has reached viral threshold
     */
    protected function checkViralThreshold(float $rawScore): bool
    {
        // Post is viral if:
        // - Has significant engagement
        // - Engagement rate is high relative to impressions
        // - Growing faster than average

        $engagementRate = $this->impressions_count > 0
            ? ($this->likes_count + $this->comments_count + $this->shares_count) / $this->impressions_count
            : 0;

        return $rawScore > 100 && $engagementRate > 0.05; // 5% engagement rate threshold
    }

    // ==================== CONTENT PROCESSING ====================

    /**
     * Extract and sync hashtags from content
     */
    public function extractAndSyncHashtags(): void
    {
        if (empty($this->content)) {
            return;
        }

        // Extract hashtags (supports Unicode letters including Swahili)
        // \p{L} matches any Unicode letter, \p{N} matches any Unicode number
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $this->content, $matches);

        if (empty($matches[1])) {
            $this->hashtags()->sync([]);
            return;
        }

        $hashtagIds = [];
        foreach ($matches[1] as $tagName) {
            $hashtag = Hashtag::firstOrCreate(
                ['name_normalized' => strtolower($tagName)],
                ['name' => $tagName]
            );
            $hashtag->increment('posts_count');
            $hashtag->increment('usage_count_24h');
            $hashtag->increment('usage_count_7d');
            $hashtagIds[] = $hashtag->id;
        }

        $this->hashtags()->sync($hashtagIds);
    }

    /**
     * Detect if this is a short video (TikTok/Reels/Shorts style)
     */
    public function detectShortVideo(): bool
    {
        if ($this->post_type !== self::TYPE_VIDEO) {
            return false;
        }

        $videoMedia = $this->media()->where('media_type', 'video')->first();
        if (!$videoMedia || !$videoMedia->duration) {
            return false;
        }

        // Short video is <= 60 seconds (like Reels/Shorts)
        return $videoMedia->duration <= 60;
    }

    /**
     * Update short video flag based on media
     */
    public function updateShortVideoFlag(): void
    {
        $this->update(['is_short_video' => $this->detectShortVideo()]);
    }

    // ==================== QUERY SCOPES ====================

    public function scopePublic($query)
    {
        return $query->where('privacy', self::PRIVACY_PUBLIC);
    }

    public function scopeVisibleToFriends($query)
    {
        return $query->whereIn('privacy', [self::PRIVACY_PUBLIC, self::PRIVACY_FRIENDS]);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at');
    }

    public function scopeReadyToPublish($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', Carbon::now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeShortVideos($query)
    {
        return $query->where('is_short_video', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeViral($query)
    {
        return $query->where('is_viral', true);
    }

    public function scopeTrending($query)
    {
        return $query->orderByDesc('trending_score');
    }

    public function scopeByEngagement($query)
    {
        return $query->orderByDesc('engagement_score');
    }

    public function scopeNearby($query, float $lat, float $lng, float $radiusKm = 50)
    {
        // Haversine formula for distance
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        return $query->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw("$haversine < ?", [$lat, $lng, $lat, $radiusKm])
            ->orderByRaw("$haversine", [$lat, $lng, $lat]);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('content_category', $category);
    }

    public function scopeWithHashtag($query, string $hashtag)
    {
        $normalized = strtolower(ltrim($hashtag, '#'));
        return $query->whereHas('hashtags', function ($q) use ($normalized) {
            $q->where('name_normalized', $normalized);
        });
    }

    public function scopeAudioPosts($query)
    {
        return $query->whereIn('post_type', [self::TYPE_AUDIO, self::TYPE_AUDIO_TEXT]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('post_type', $type);
    }

    public function scopeWithMusic($query)
    {
        return $query->whereNotNull('music_track_id');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if post is an audio post
     */
    public function isAudioPost(): bool
    {
        return in_array($this->post_type, [self::TYPE_AUDIO, self::TYPE_AUDIO_TEXT]);
    }

    /**
     * Check if post has background audio/music
     */
    public function hasMusic(): bool
    {
        return $this->music_track_id !== null;
    }

    /**
     * Get audio URL
     */
    public function getAudioUrlAttribute(): ?string
    {
        if (!$this->audio_path) {
            return null;
        }
        return asset('storage/' . $this->audio_path);
    }

    /**
     * Get cover image URL
     */
    public function getCoverImageUrlAttribute(): ?string
    {
        if (!$this->cover_image_path) {
            return null;
        }
        return asset('storage/' . $this->cover_image_path);
    }

    /**
     * Format audio duration for display (mm:ss)
     */
    public function getFormattedAudioDurationAttribute(): ?string
    {
        if (!$this->audio_duration) {
            return null;
        }
        $minutes = floor($this->audio_duration / 60);
        $seconds = $this->audio_duration % 60;
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    // ==================== FEED ALGORITHMS ====================

    /**
     * Get personalized "For You" feed posts
     * Combines: trending + interests + engagement + freshness
     */
    public static function forYouFeed(int $userId, int $limit = 20, int $offset = 0)
    {
        // Get user interests
        $userInterests = UserInterest::where('user_id', $userId)
            ->orderByDesc('weight')
            ->limit(20)
            ->pluck('interest_value', 'interest_type')
            ->toArray();

        return static::query()
            ->public()
            ->published()
            ->where('user_id', '!=', $userId)
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount('comments')
            // Combine trending and engagement scores
            ->orderByRaw('(trending_score * 0.4 + engagement_score * 0.6) DESC')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Get following feed (posts from friends)
     */
    public static function followingFeed(int $userId, array $friendIds, int $limit = 20, int $offset = 0)
    {
        return static::query()
            ->whereIn('user_id', $friendIds)
            ->visibleToFriends()
            ->published()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount('comments')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Get short videos feed (TikTok/Reels style)
     */
    public static function shortVideosFeed(int $userId, int $limit = 20, int $offset = 0)
    {
        return static::query()
            ->public()
            ->published()
            ->shortVideos()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->orderByRaw('(trending_score * 0.5 + engagement_score * 0.5) DESC')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Get trending posts
     */
    public static function trendingFeed(int $limit = 20, int $offset = 0)
    {
        return static::query()
            ->public()
            ->published()
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->trending()
            ->offset($offset)
            ->limit($limit)
            ->get();
    }
}
