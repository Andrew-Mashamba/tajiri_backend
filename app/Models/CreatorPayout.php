<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'amount',
        'payment_method',
        'account_number',
        'account_name',
        'provider',
        'status',
        'transaction_id',
        'failure_reason',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(string $transactionId): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'transaction_id' => $transactionId,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }
}
