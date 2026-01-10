<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'friend_id',
        'status',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_BLOCKED = 'blocked';

    /**
     * Get the user who initiated the friendship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the friend.
     */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'friend_id');
    }

    /**
     * Accept the friendship request.
     */
    public function accept(): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Block the user.
     */
    public function block(): void
    {
        $this->update([
            'status' => self::STATUS_BLOCKED,
        ]);
    }

    /**
     * Check if friendship is accepted.
     */
    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Check if friendship is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if user is blocked.
     */
    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /**
     * Scope for accepted friendships.
     */
    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    /**
     * Scope for pending friendships.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Check if two users are friends.
     */
    public static function areFriends(int $userId1, int $userId2): bool
    {
        return self::where(function ($query) use ($userId1, $userId2) {
            $query->where('user_id', $userId1)->where('friend_id', $userId2);
        })->orWhere(function ($query) use ($userId1, $userId2) {
            $query->where('user_id', $userId2)->where('friend_id', $userId1);
        })->where('status', self::STATUS_ACCEPTED)->exists();
    }

    /**
     * Get friendship between two users.
     */
    public static function getBetween(int $userId1, int $userId2): ?self
    {
        return self::where(function ($query) use ($userId1, $userId2) {
            $query->where('user_id', $userId1)->where('friend_id', $userId2);
        })->orWhere(function ($query) use ($userId1, $userId2) {
            $query->where('user_id', $userId2)->where('friend_id', $userId1);
        })->first();
    }
}
