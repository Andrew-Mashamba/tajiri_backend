<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamQuestion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stream_id',
        'user_id',
        'question',
        'upvotes',
        'is_answered',
        'created_at',
        'answered_at',
    ];

    protected $casts = [
        'is_answered' => 'boolean',
        'created_at' => 'datetime',
        'answered_at' => 'datetime',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function upvoteRecords(): HasMany
    {
        return $this->hasMany(StreamQuestionUpvote::class, 'question_id');
    }
}
