<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Story extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'media_type',
        'media_path',
        'thumbnail_path',
        'caption',
        'duration',
        'text_overlays',
        'stickers',
        'filter',
        'music_id',
        'music_start',
        'background_color',
        'link_url',
        'location_name',
        'latitude',
        'longitude',
        'allow_replies',
        'allow_sharing',
        'privacy',
        'views_count',
        'reactions_count',
        'expires_at',
    ];

    protected $casts = [
        'text_overlays' => 'array',
        'stickers' => 'array',
        'allow_replies' => 'boolean',
        'allow_sharing' => 'boolean',
        'expires_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function music(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class, 'music_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(StoryReply::class);
    }

    public function highlights(): BelongsToMany
    {
        return $this->belongsToMany(StoryHighlight::class, 'highlight_stories', 'story_id', 'highlight_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    public function hasViewed(int $userId): bool
    {
        return $this->views()->where('viewer_id', $userId)->exists();
    }

    public function markViewed(int $userId): void
    {
        if (!$this->hasViewed($userId)) {
            $this->views()->create([
                'viewer_id' => $userId,
                'viewed_at' => now(),
            ]);
            $this->increment('views_count');
        }
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForUser($query, int $userId, array $friendIds = [], array $closeFriendIds = [])
    {
        return $query->where(function ($q) use ($userId, $friendIds, $closeFriendIds) {
            $q->where('privacy', 'everyone')
              ->orWhere(function ($inner) use ($friendIds) {
                  $inner->where('privacy', 'friends')
                        ->whereIn('user_id', $friendIds);
              })
              ->orWhere(function ($inner) use ($closeFriendIds) {
                  $inner->where('privacy', 'close_friends')
                        ->whereIn('user_id', $closeFriendIds);
              })
              ->orWhere('user_id', $userId);
        });
    }
}
