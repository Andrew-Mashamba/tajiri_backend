<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReply extends Model
{
    protected $fillable = [
        'story_id',
        'user_id',
        'content',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
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
