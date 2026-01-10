<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'type',
        'gross_amount',
        'platform_fee',
        'net_amount',
        'subscription_id',
        'tip_id',
        'gift_id',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    const TYPE_SUBSCRIPTION = 'subscription';
    const TYPE_TIP = 'tip';
    const TYPE_GIFT = 'gift';

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tip(): BelongsTo
    {
        return $this->belongsTo(CreatorTip::class, 'tip_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(StreamGift::class, 'gift_id');
    }

    public static function createFromSubscription(Subscription $subscription): self
    {
        $platformFee = $subscription->amount_paid * 0.15; // 15% platform fee
        return self::create([
            'creator_id' => $subscription->creator_id,
            'type' => self::TYPE_SUBSCRIPTION,
            'gross_amount' => $subscription->amount_paid,
            'platform_fee' => $platformFee,
            'net_amount' => $subscription->amount_paid - $platformFee,
            'subscription_id' => $subscription->id,
            'status' => self::STATUS_PENDING,
        ]);
    }

    public static function createFromTip(CreatorTip $tip): self
    {
        $platformFee = $tip->amount * 0.10; // 10% platform fee for tips
        return self::create([
            'creator_id' => $tip->creator_id,
            'type' => self::TYPE_TIP,
            'gross_amount' => $tip->amount,
            'platform_fee' => $platformFee,
            'net_amount' => $tip->amount - $platformFee,
            'tip_id' => $tip->id,
            'status' => self::STATUS_PENDING,
        ]);
    }
}
