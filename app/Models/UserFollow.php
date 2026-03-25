<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFollow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'follower_id',
        'following_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function follower(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'follower_id');
    }

    public function following(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'following_id');
    }

    /**
     * Check if user A follows user B.
     */
    public static function isFollowing(int $followerId, int $followingId): bool
    {
        return static::where('follower_id', $followerId)
            ->where('following_id', $followingId)
            ->exists();
    }
}
