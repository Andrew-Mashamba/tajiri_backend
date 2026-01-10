<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'sender_id',
        'recipient_id',
        'amount',
        'fee',
        'status',
        'note',
        'sender_transaction_id',
        'recipient_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REVERSED = 'reversed';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transfer) {
            if (empty($transfer->transfer_id)) {
                $transfer->transfer_id = 'TRF' . strtoupper(Str::random(12));
            }
        });
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'recipient_id');
    }

    public function senderTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'sender_transaction_id');
    }

    public function recipientTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'recipient_transaction_id');
    }

    public static function send(int $senderId, int $recipientId, float $amount, string $note = null): ?self
    {
        $senderWallet = Wallet::getOrCreate($senderId);
        $recipientWallet = Wallet::getOrCreate($recipientId);

        // Calculate fee (1% with min 100 TZS, max 5000 TZS)
        $fee = max(100, min(5000, $amount * 0.01));

        if (!$senderWallet->canAfford($amount + $fee)) {
            return null;
        }

        // Create transfer record
        $transfer = self::create([
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'amount' => $amount,
            'fee' => $fee,
            'note' => $note,
            'status' => self::STATUS_PENDING,
        ]);

        // Debit sender
        $senderTxn = $senderWallet->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $senderId,
            'type' => WalletTransaction::TYPE_TRANSFER_OUT,
            'amount' => $amount,
            'fee' => $fee,
            'balance_before' => $senderWallet->balance,
            'balance_after' => $senderWallet->balance - $amount - $fee,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'description' => "Tuma kwa {$recipientWallet->user->first_name}",
            'completed_at' => now(),
        ]);

        $senderWallet->decrement('balance', $amount + $fee);

        // Credit recipient
        $recipientTxn = $recipientWallet->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $recipientId,
            'type' => WalletTransaction::TYPE_TRANSFER_IN,
            'amount' => $amount,
            'fee' => 0,
            'balance_before' => $recipientWallet->balance,
            'balance_after' => $recipientWallet->balance + $amount,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'description' => "Pokea kutoka {$senderWallet->user->first_name}",
            'completed_at' => now(),
        ]);

        $recipientWallet->increment('balance', $amount);

        // Update transfer
        $transfer->update([
            'status' => self::STATUS_COMPLETED,
            'sender_transaction_id' => $senderTxn->id,
            'recipient_transaction_id' => $recipientTxn->id,
        ]);

        return $transfer;
    }
}
