<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Clip extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'video_path',
        'thumbnail_path',
        'caption',
        'duration',
        'music_id',
        'music_start',
        'hashtags',
        'mentions',
        'effects',
        'location_name',
        'latitude',
        'longitude',
        'privacy',
        'allow_comments',
        'allow_duet',
        'allow_stitch',
        'allow_download',
        'views_count',
        'likes_count',
        'comments_count',
        'shares_count',
        'saves_count',
        'duets_count',
        'is_featured',
        'status',
        'original_clip_id',
        'clip_type',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'mentions' => 'array',
        'effects' => 'array',
        'allow_comments' => 'boolean',
        'allow_duet' => 'boolean',
        'allow_stitch' => 'boolean',
        'allow_download' => 'boolean',
        'is_featured' => 'boolean',
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

    public function originalClip(): BelongsTo
    {
        return $this->belongsTo(Clip::class, 'original_clip_id');
    }

    public function duets(): HasMany
    {
        return $this->hasMany(Clip::class, 'original_clip_id')->where('clip_type', 'duet');
    }

    public function stitches(): HasMany
    {
        return $this->hasMany(Clip::class, 'original_clip_id')->where('clip_type', 'stitch');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'clip_likes', 'clip_id', 'user_id')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ClipComment::class)->whereNull('parent_id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(ClipComment::class);
    }

    public function saves(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'saved_clips', 'clip_id', 'user_id')->withTimestamps();
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ClipShare::class);
    }

    public function hashtagRelations(): BelongsToMany
    {
        return $this->belongsToMany(ClipHashtag::class, 'clip_hashtag_pivot', 'clip_id', 'hashtag_id');
    }

    public function isLikedBy(int $userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function isSavedBy(int $userId): bool
    {
        return $this->saves()->where('user_id', $userId)->exists();
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeForYou($query)
    {
        return $query->published()
            ->where('privacy', 'public')
            ->orderByDesc('created_at');
    }
}
