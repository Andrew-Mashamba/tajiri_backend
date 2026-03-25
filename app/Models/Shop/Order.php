<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'buyer_id',
        'seller_id',
        'product_id',
        'quantity',
        'unit_price',
        'subtotal',
        'delivery_fee',
        'total_amount',
        'currency',
        'status',
        'delivery_method',
        'delivery_address',
        'delivery_notes',
        'tracking_number',
        'estimated_delivery',
        'wallet_transaction_id',
        'payment_status',
        'paid_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
        'estimated_delivery' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => ['completed', 'refunded'],
        'completed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    public static function generateOrderNumber(): string
    {
        $year = date('Y');
        $latest = self::whereYear('created_at', $year)->max('id') ?? 0;
        return 'ORD-' . $year . '-' . str_pad($latest + 1, 6, '0', STR_PAD_LEFT);
    }

    // Relationships

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'buyer_id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'cancelled_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductReview::class);
    }

    // Helpers

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? []);
    }

    public function isBuyer(int $userId): bool
    {
        return $this->buyer_id === $userId;
    }

    public function isSeller(int $userId): bool
    {
        return $this->seller_id === $userId;
    }

    public function isParticipant(int $userId): bool
    {
        return $this->isBuyer($userId) || $this->isSeller($userId);
    }

    public function canBeCancelledBy(int $userId): bool
    {
        if ($this->isBuyer($userId)) {
            return $this->status === self::STATUS_PENDING;
        }
        if ($this->isSeller($userId)) {
            return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED]);
        }
        return false;
    }
}
