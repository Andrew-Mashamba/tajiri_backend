<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'poll_id',
        'option_id',
        'user_id',
    ];

    /**
     * Get the poll.
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Get the option.
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
