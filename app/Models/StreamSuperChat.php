<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamSuperChat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stream_id',
        'user_id',
        'message',
        'amount',
        'tier',
        'duration',
        'payment_method',
        'payment_reference',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    const TIER_LOW = 'low';
    const TIER_MEDIUM = 'medium';
    const TIER_HIGH = 'high';

    // Tier thresholds in TZS
    const TIER_THRESHOLDS = [
        'low' => ['min' => 1000, 'max' => 2999, 'duration' => 5],
        'medium' => ['min' => 3000, 'max' => 9999, 'duration' => 10],
        'high' => ['min' => 10000, 'max' => PHP_INT_MAX, 'duration' => 15],
    ];

    public static function calculateTier(float $amount): array
    {
        foreach (self::TIER_THRESHOLDS as $tier => $config) {
            if ($amount >= $config['min'] && $amount <= $config['max']) {
                return ['tier' => $tier, 'duration' => $config['duration']];
            }
        }
        return ['tier' => 'low', 'duration' => 5];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
