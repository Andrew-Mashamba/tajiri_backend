<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorTier extends Model
{
    protected $table = 'creator_tiers';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id', 'tier', 'promoted_at', 'mwanzo_expires_at', 'next_review_at',
        'strike_count', 'monetization_paused', 'last_active_at', 'is_id_verified',
        'payout_preference',
    ];

    protected $casts = [
        'promoted_at'         => 'datetime',
        'mwanzo_expires_at'   => 'datetime',
        'next_review_at'      => 'datetime',
        'last_active_at'      => 'datetime',
        'monetization_paused' => 'boolean',
        'is_id_verified'      => 'boolean',
        'strike_count'        => 'int',
    ];

    public const TIERS = ['mwanzo', 'standard', 'verified', 'partner'];
    public const TIER_RANK = ['mwanzo' => 0, 'standard' => 1, 'verified' => 2, 'partner' => 3];

    /**
     * Find-or-create the tier row for a user. New rows default to mwanzo with a 30-day boost.
     */
    public static function forUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'tier'              => 'mwanzo',
                'promoted_at'       => now(),
                'mwanzo_expires_at' => now()->addDays(30),
                'next_review_at'    => now()->addDays(7),
                'last_active_at'    => now(),
            ]
        );
    }

    public function isAtLeast(string $tier): bool
    {
        return (self::TIER_RANK[$this->tier] ?? -1) >= (self::TIER_RANK[$tier] ?? 99);
    }

    public function isMwanzoActive(): bool
    {
        return $this->mwanzo_expires_at && $this->mwanzo_expires_at->isFuture();
    }
}
