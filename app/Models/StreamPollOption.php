<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamPollOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'poll_id',
        'text',
        'votes',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(StreamPoll::class, 'poll_id');
    }

    public function voteRecords(): HasMany
    {
        return $this->hasMany(StreamPollVote::class, 'option_id');
    }
}
