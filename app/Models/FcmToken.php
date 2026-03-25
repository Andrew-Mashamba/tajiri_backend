<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'fcm_token',
        'platform',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Store or update an FCM token for a user/device.
     */
    public static function storeToken(int $userId, string $deviceId, string $token, ?string $platform = null): self
    {
        return self::updateOrCreate(
            ['user_id' => $userId, 'device_id' => $deviceId],
            ['fcm_token' => $token, 'platform' => $platform]
        );
    }

    /**
     * Remove token for a user/device.
     */
    public static function removeToken(int $userId, string $deviceId): bool
    {
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->delete() > 0;
    }

    /**
     * Get all FCM tokens for a user.
     */
    public static function getTokensForUser(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('fcm_token')
            ->toArray();
    }

    /**
     * Get all FCM tokens for multiple users.
     */
    public static function getTokensForUsers(array $userIds): array
    {
        return self::whereIn('user_id', $userIds)
            ->pluck('fcm_token')
            ->toArray();
    }
}
