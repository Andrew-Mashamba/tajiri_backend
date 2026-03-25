<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MusicArtist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'image_path',
        'bio',
        'is_verified',
        'followers_count',
        'monthly_listeners',
        'tracks_count',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function tracks(): HasMany
    {
        return $this->hasMany(MusicTrack::class, 'artist_id');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'artist_follows', 'artist_id', 'user_id');
    }

    public function isFollowedBy(int $userId): bool
    {
        return $this->followers()->where('user_id', $userId)->exists();
    }
}
