<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'name',
        'description',
        'price',
        'billing_period',
        'benefits',
        'is_active',
        'subscriber_count',
        'order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'benefits' => 'array',
        'is_active' => 'boolean',
        'subscriber_count' => 'integer',
        'order' => 'integer',
    ];

    const PERIOD_MONTHLY = 'monthly';
    const PERIOD_YEARLY = 'yearly';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'tier_id');
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()->where('status', 'active');
    }

    public function incrementSubscribers(): void
    {
        $this->increment('subscriber_count');
    }

    public function decrementSubscribers(): void
    {
        $this->decrement('subscriber_count');
    }
}
