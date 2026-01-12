<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}
