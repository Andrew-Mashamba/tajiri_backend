<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'fee',
        'balance_before',
        'balance_after',
        'status',
        'payment_method',
        'provider',
        'external_transaction_id',
        'description',
        'metadata',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAWAL = 'withdrawal';
    const TYPE_TRANSFER_IN = 'transfer_in';
    const TYPE_TRANSFER_OUT = 'transfer_out';
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    public static function generateId(): string
    {
        return 'TXN' . strtoupper(Str::random(12));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function fail(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    public function isCredit(): bool
    {
        return in_array($this->type, [self::TYPE_DEPOSIT, self::TYPE_TRANSFER_IN, self::TYPE_REFUND]);
    }

    public function isDebit(): bool
    {
        return in_array($this->type, [self::TYPE_WITHDRAWAL, self::TYPE_TRANSFER_OUT, self::TYPE_PAYMENT]);
    }

    public function getTotalAttribute(): float
    {
        return $this->amount + $this->fee;
    }

    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_DEPOSIT => 'Uingizaji',
            self::TYPE_WITHDRAWAL => 'Uondoaji',
            self::TYPE_TRANSFER_IN => 'Upokeaji',
            self::TYPE_TRANSFER_OUT => 'Ulipaji',
            self::TYPE_PAYMENT => 'Malipo',
            self::TYPE_REFUND => 'Rudisho',
            default => $this->type,
        };
    }
}
