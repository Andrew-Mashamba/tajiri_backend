<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamQuestionUpvote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'question_id',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(StreamQuestion::class, 'question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
