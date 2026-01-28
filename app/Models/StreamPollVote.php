<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamPollVote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'poll_id',
        'option_id',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(StreamPoll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(StreamPollOption::class, 'option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
