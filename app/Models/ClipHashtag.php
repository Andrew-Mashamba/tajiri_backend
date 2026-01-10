<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClipHashtag extends Model
{
    protected $fillable = [
        'tag',
        'clips_count',
        'views_count',
        'is_trending',
    ];

    protected $casts = [
        'is_trending' => 'boolean',
    ];

    public function clips(): BelongsToMany
    {
        return $this->belongsToMany(Clip::class, 'clip_hashtag_pivot', 'hashtag_id', 'clip_id');
    }

    public function scopeTrending($query)
    {
        return $query->where('is_trending', true)->orderByDesc('clips_count');
    }
}
