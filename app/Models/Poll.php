<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'question',
        'description',
        'creator_id',
        'post_id',
        'group_id',
        'page_id',
        'ends_at',
        'is_multiple_choice',
        'is_anonymous',
        'show_results_before_voting',
        'allow_add_options',
        'total_votes',
        'status',
    ];

    protected $casts = [
        'ends_at' => 'datetime',
        'is_multiple_choice' => 'boolean',
        'is_anonymous' => 'boolean',
        'show_results_before_voting' => 'boolean',
        'allow_add_options' => 'boolean',
        'total_votes' => 'integer',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the creator of the poll.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    /**
     * Get the post if poll is attached to a post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the group if poll is in a group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the page if poll is on a page.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get poll options.
     */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('order');
    }

    /**
     * Get all votes on this poll.
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Check if poll has ended.
     */
    public function hasEnded(): bool
    {
        if ($this->status === 'closed') {
            return true;
        }
        return $this->ends_at && $this->ends_at->isPast();
    }

    /**
     * Check if poll is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->hasEnded();
    }

    /**
     * Check if user has voted on this poll.
     */
    public function hasVoted(int $userId): bool
    {
        return $this->votes()->where('user_id', $userId)->exists();
    }

    /**
     * Get user's votes on this poll.
     */
    public function getUserVotes(int $userId): array
    {
        return $this->votes()
            ->where('user_id', $userId)
            ->pluck('option_id')
            ->toArray();
    }

    /**
     * Check if user can vote.
     */
    public function canVote(int $userId): bool
    {
        if ($this->hasEnded()) {
            return false;
        }

        if (!$this->is_multiple_choice && $this->hasVoted($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Check if user can see results.
     */
    public function canSeeResults(int $userId): bool
    {
        return $this->show_results_before_voting ||
               $this->hasVoted($userId) ||
               $this->hasEnded() ||
               $this->creator_id === $userId;
    }

    /**
     * Increment total votes.
     */
    public function incrementVotes(): void
    {
        $this->increment('total_votes');
    }

    /**
     * Decrement total votes.
     */
    public function decrementVotes(): void
    {
        $this->decrement('total_votes');
    }

    /**
     * Scope for active polls.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('status', 'active')
                      ->where(function ($dateQ) {
                          $dateQ->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                      });
            });
        });
    }

    /**
     * Scope for ended polls.
     */
    public function scopeEnded($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'closed')
              ->orWhere(function ($inner) {
                  $inner->whereNotNull('ends_at')
                        ->where('ends_at', '<=', now());
              });
        });
    }
}
