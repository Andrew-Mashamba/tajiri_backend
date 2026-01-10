<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscriber_id',
        'creator_id',
        'tier_id',
        'status',
        'amount_paid',
        'started_at',
        'expires_at',
        'cancelled_at',
        'auto_renew',
        'payment_method',
        'transaction_id',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'auto_renew' => 'boolean',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_PAUSED = 'paused';

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'subscriber_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'tier_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at > now();
    }

    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);
        $this->tier->decrementSubscribers();
    }

    public function renew(): void
    {
        $period = $this->tier->billing_period === 'yearly' ? 1 : 0;
        $months = $this->tier->billing_period === 'yearly' ? 0 : 1;

        $this->update([
            'status' => self::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addYears($period)->addMonths($months),
        ]);
    }
}
