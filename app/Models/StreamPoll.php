<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamPoll extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stream_id',
        'question',
        'is_closed',
        'created_by',
        'created_at',
        'closed_at',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'created_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(StreamPollOption::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(StreamPollVote::class, 'poll_id');
    }

    public function getTotalVotesAttribute(): int
    {
        return $this->options->sum('votes');
    }
}
