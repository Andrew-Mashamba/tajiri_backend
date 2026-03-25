<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedUser extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'blocked_user_id',
    ];

    /**
     * Get the user who blocked.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the blocked user.
     */
    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'blocked_user_id');
    }

    /**
     * Check if user A has blocked user B.
     */
    public static function isBlocked(int $userId, int $blockedUserId): bool
    {
        return self::where('user_id', $userId)
            ->where('blocked_user_id', $blockedUserId)
            ->exists();
    }

    /**
     * Check if either user has blocked the other.
     */
    public static function isEitherBlocked(int $userId1, int $userId2): bool
    {
        return self::where(function ($q) use ($userId1, $userId2) {
            $q->where('user_id', $userId1)->where('blocked_user_id', $userId2);
        })->orWhere(function ($q) use ($userId1, $userId2) {
            $q->where('user_id', $userId2)->where('blocked_user_id', $userId1);
        })->exists();
    }

    /**
     * Get all user IDs that a user has blocked.
     */
    public static function getBlockedIds(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('blocked_user_id')
            ->toArray();
    }

    /**
     * Get all user IDs that have blocked this user.
     */
    public static function getBlockedByIds(int $userId): array
    {
        return self::where('blocked_user_id', $userId)
            ->pluck('user_id')
            ->toArray();
    }

    /**
     * Get all blocked user IDs (both directions) for filtering.
     */
    public static function getAllBlockedIds(int $userId): array
    {
        return array_unique(array_merge(
            self::getBlockedIds($userId),
            self::getBlockedByIds($userId)
        ));
    }
}
