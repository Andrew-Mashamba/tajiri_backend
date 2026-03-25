<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPresence extends Model
{
    protected $table = 'user_presence';

    protected $fillable = [
        'user_id',
        'last_seen_at',
        'is_online',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_online' => 'boolean',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Update presence for a user (heartbeat).
     */
    public static function heartbeat(int $userId): self
    {
        return self::updateOrCreate(
            ['user_id' => $userId],
            ['last_seen_at' => now(), 'is_online' => true]
        );
    }

    /**
     * Mark user as offline.
     */
    public static function goOffline(int $userId): void
    {
        self::where('user_id', $userId)->update([
            'is_online' => false,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Mark stale users as offline (no heartbeat in X minutes).
     */
    public static function markStaleOffline(int $minutes = 5): int
    {
        return self::where('is_online', true)
            ->where('last_seen_at', '<', now()->subMinutes($minutes))
            ->update(['is_online' => false]);
    }

    /**
     * Get online user IDs from a list.
     */
    public static function getOnlineUserIds(array $userIds): array
    {
        return self::whereIn('user_id', $userIds)
            ->where('is_online', true)
            ->pluck('user_id')
            ->toArray();
    }
}
