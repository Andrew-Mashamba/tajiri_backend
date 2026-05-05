<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentDocument extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_type', 'source_id', 'title', 'body',
        'media_types', 'hashtags', 'mentions', 'language',
        'creator_id', 'creator_tier', 'creator_authority',
        'quality_score', 'engagement_score', 'freshness_score',
        'content_rank', 'trending_score', 'spam_score', 'composite_score',
        'content_tier', 'privacy', 'region_name', 'district_name', 'category',
        'published_at', 'indexed_at', 'scores_updated_at',
    ];

    protected $casts = [
        'media_types' => 'array',
        'hashtags' => 'array',
        'mentions' => 'array',
        'quality_score' => 'float',
        'engagement_score' => 'float',
        'freshness_score' => 'float',
        'content_rank' => 'float',
        'trending_score' => 'float',
        'spam_score' => 'float',
        'composite_score' => 'float',
        'creator_authority' => 'float',
        'published_at' => 'datetime',
        'indexed_at' => 'datetime',
        'scores_updated_at' => 'datetime',
    ];

    // Source type enum values
    public const TYPE_POST = 'post';
    public const TYPE_CLIP = 'clip';
    public const TYPE_STORY = 'story';
    public const TYPE_MUSIC = 'music';
    public const TYPE_STREAM = 'stream';
    public const TYPE_EVENT = 'event';
    public const TYPE_CAMPAIGN = 'campaign';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_GROUP = 'group';
    public const TYPE_PAGE = 'page';
    public const TYPE_USER_PROFILE = 'user_profile';
    public const TYPE_GOSSIP_THREAD = 'gossip_thread';

    // Content tiers
    public const TIER_VIRAL = 'viral';
    public const TIER_HIGH = 'high';
    public const TIER_MEDIUM = 'medium';
    public const TIER_LOW = 'low';
    public const TIER_BLACKHOLE = 'blackhole';

    public function creator()
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function graphEdgesAsSource()
    {
        return $this->hasMany(ContentGraphEdge::class, 'source_id')
            ->where('source_type', 'doc');
    }

    public function graphEdgesAsTarget()
    {
        return $this->hasMany(ContentGraphEdge::class, 'target_id')
            ->where('target_type', 'doc');
    }

    /**
     * Find document by source.
     */
    public static function findBySource(string $type, int $id): ?self
    {
        return static::where('source_type', $type)
            ->where('source_id', $id)
            ->first();
    }

    /**
     * Recompute composite_score and content_tier from current score fields.
     * Single source of truth for the scoring formula — called by ingestion,
     * signal sync, freshness refresh, etc.
     *
     * @param bool $save Whether to persist changes to the database.
     */
    public function recomputeCompositeAndTier(bool $save = true): void
    {
        $weights = \App\Models\ScoringConfig::allWeights();

        $this->composite_score = round(
            ($this->freshness_score * ($weights['w_freshness'] ?? 0.25)) +
            ($this->engagement_score * ($weights['w_engagement'] ?? 0.30)) +
            ($this->quality_score * 10 * ($weights['w_quality'] ?? 0.15)) +
            ($this->content_rank * ($weights['w_content_rank'] ?? 0.15)) +
            ($this->creator_authority * ($weights['w_creator_auth'] ?? 0.10)) +
            ($this->trending_score * ($weights['w_trending'] ?? 0.05)),
            2
        );

        $this->content_tier = match (true) {
            $this->spam_score > 7 => 'blackhole',
            $this->composite_score > config('content-engine.tiers.viral', 85) => 'viral',
            $this->composite_score > config('content-engine.tiers.high', 60) => 'high',
            $this->composite_score > config('content-engine.tiers.medium', 30) => 'medium',
            $this->composite_score > config('content-engine.tiers.low', 10) => 'low',
            default => 'blackhole',
        };

        $this->scores_updated_at = now();

        if ($save) {
            $this->save();
        }
    }
}
