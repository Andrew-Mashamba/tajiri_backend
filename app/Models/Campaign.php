<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';

    const CATEGORIES = [
        'medical', 'education', 'emergency', 'funeral', 'wedding',
        'business', 'community', 'religious', 'sports', 'arts',
        'environment', 'other',
    ];

    protected $fillable = [
        'user_id', 'title', 'story', 'short_description',
        'goal_amount', 'raised_amount', 'currency', 'status',
        'category', 'is_verified', 'deadline', 'cover_image_path',
        'media_paths', 'donors_count', 'shares_count', 'views_count',
        'allow_anonymous_donations', 'minimum_donation', 'is_urgent',
        'bank_name', 'account_number', 'mobile_money_number',
    ];

    protected $casts = [
        'goal_amount' => 'decimal:2',
        'raised_amount' => 'decimal:2',
        'minimum_donation' => 'decimal:2',
        'is_verified' => 'boolean',
        'is_urgent' => 'boolean',
        'allow_anonymous_donations' => 'boolean',
        'media_paths' => 'array',
        'deadline' => 'datetime',
        'donors_count' => 'integer',
        'shares_count' => 'integer',
        'views_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(CampaignDonation::class);
    }

    public function completedDonations(): HasMany
    {
        return $this->hasMany(CampaignDonation::class)->where('status', 'completed');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(CampaignWithdrawal::class);
    }

    public function getProgressPercentAttribute(): float
    {
        if ($this->goal_amount <= 0) return 0;
        return min(100, round(($this->raised_amount / $this->goal_amount) * 100, 1));
    }

    public function getAvailableBalanceAttribute(): float
    {
        $withdrawnOrPending = $this->withdrawals()
            ->whereNotIn('status', ['rejected', 'failed'])
            ->sum('amount');
        return max(0, $this->raised_amount - $withdrawnOrPending);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
