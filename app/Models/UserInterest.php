<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInterest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'interest_type',
        'interest_value',
        'weight',
        'interaction_count',
        'last_interaction_at',
    ];

    protected $casts = [
        'weight' => 'decimal:4',
        'interaction_count' => 'integer',
        'last_interaction_at' => 'datetime',
    ];

    const TYPE_HASHTAG = 'hashtag';
    const TYPE_CATEGORY = 'category';
    const TYPE_CREATOR = 'creator';

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Record an interaction to update interest weights
     */
    public static function recordInteraction(
        int $userId,
        string $type,
        string $value,
        float $strength = 0.1
    ): self {
        $interest = static::firstOrCreate(
            [
                'user_id' => $userId,
                'interest_type' => $type,
                'interest_value' => $value,
            ],
            [
                'weight' => 0,
                'interaction_count' => 0,
            ]
        );

        // Increase weight with diminishing returns
        $newWeight = min(1.0, $interest->weight + ($strength * (1 - $interest->weight)));

        $interest->update([
            'weight' => round($newWeight, 4),
            'interaction_count' => $interest->interaction_count + 1,
            'last_interaction_at' => now(),
        ]);

        return $interest;
    }

    /**
     * Decay all interests over time (run daily)
     */
    public static function decayWeights(float $decayRate = 0.95): void
    {
        static::query()->update([
            'weight' => \DB::raw("weight * $decayRate"),
        ]);

        // Remove interests with very low weight
        static::where('weight', '<', 0.01)->delete();
    }

    /**
     * Get top interests for a user
     */
    public static function getTopInterests(int $userId, int $limit = 20)
    {
        return static::where('user_id', $userId)
            ->orderByDesc('weight')
            ->limit($limit)
            ->get();
    }

    /**
     * Get interests by type
     */
    public static function getInterestsByType(int $userId, string $type, int $limit = 10)
    {
        return static::where('user_id', $userId)
            ->where('interest_type', $type)
            ->orderByDesc('weight')
            ->limit($limit)
            ->pluck('interest_value')
            ->toArray();
    }
}
