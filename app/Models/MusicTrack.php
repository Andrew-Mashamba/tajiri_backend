<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MusicTrack extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'artist_id',
        'uploaded_by',
        'album',
        'audio_path',
        'cover_path',
        'duration',
        'genre',
        'bpm',
        'bitrate',
        'sample_rate',
        'channels',
        'file_size',
        'codec',
        'file_format',
        'composer',
        'publisher',
        'release_year',
        'track_number',
        'lyrics',
        'comment',
        'isrc',
        'copyright',
        'is_explicit',
        'uses_count',
        'plays_count',
        'is_featured',
        'is_trending',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'is_explicit' => 'boolean',
        'is_featured' => 'boolean',
        'is_trending' => 'boolean',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(MusicArtist::class, 'artist_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(MusicCategory::class, 'music_track_categories', 'track_id', 'category_id');
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'saved_music', 'track_id', 'user_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'music_id');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class, 'music_id');
    }

    public function incrementUses(): void
    {
        $this->increment('uses_count');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeTrending($query)
    {
        return $query->where('is_trending', true);
    }
}
