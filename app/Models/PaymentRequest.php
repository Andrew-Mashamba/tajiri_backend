<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'requester_id',
        'payer_id',
        'amount',
        'description',
        'status',
        'expires_at',
        'paid_at',
        'transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_DECLINED = 'declined';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->request_id)) {
                $request->request_id = 'REQ' . strtoupper(Str::random(12));
            }
            if (empty($request->expires_at)) {
                $request->expires_at = now()->addDays(7);
            }
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'requester_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'payer_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at > now();
    }

    public function pay(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $transfer = WalletTransfer::send(
            $this->payer_id,
            $this->requester_id,
            $this->amount,
            $this->description
        );

        if (!$transfer) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
            'transaction_id' => $transfer->senderTransaction->id,
        ]);

        return true;
    }

    public function decline(): void
    {
        $this->update(['status' => self::STATUS_DECLINED]);
    }

    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
