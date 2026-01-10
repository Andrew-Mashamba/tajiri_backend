<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReaction extends Model
{
    protected $fillable = [
        'story_id',
        'user_id',
        'emoji',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
