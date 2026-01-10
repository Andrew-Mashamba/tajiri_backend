<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'poll_id',
        'option_text',
        'image_path',
        'votes_count',
        'order',
        'added_by',
    ];

    protected $casts = [
        'votes_count' => 'integer',
        'order' => 'integer',
    ];

    /**
     * Get the poll.
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Get votes for this option.
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }

    /**
     * Get the user who added this option.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'added_by');
    }

    /**
     * Get image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    /**
     * Get percentage of votes.
     */
    public function getPercentageAttribute(): float
    {
        $total = $this->poll->total_votes;
        if ($total === 0) {
            return 0;
        }
        return round(($this->votes_count / $total) * 100, 1);
    }

    /**
     * Increment votes count.
     */
    public function incrementVotes(): void
    {
        $this->increment('votes_count');
    }

    /**
     * Decrement votes count.
     */
    public function decrementVotes(): void
    {
        $this->decrement('votes_count');
    }
}
