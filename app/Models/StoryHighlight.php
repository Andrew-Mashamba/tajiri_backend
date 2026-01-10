<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoryHighlight extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'cover_path',
        'order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'highlight_stories', 'highlight_id', 'story_id')
            ->withPivot('order')
            ->orderByPivot('order');
    }
}
