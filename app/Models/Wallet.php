<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'pending_balance',
        'currency',
        'is_active',
        'pin_hash',
        'failed_pin_attempts',
        'locked_until',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'failed_pin_attempts' => 'integer',
        'locked_until' => 'datetime',
    ];

    protected $hidden = ['pin_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function mobileMoneyAccounts(): HasMany
    {
        return $this->hasMany(MobileMoneyAccount::class, 'user_id', 'user_id');
    }

    public function setPin(string $pin): void
    {
        $this->update(['pin_hash' => Hash::make($pin)]);
    }

    public function verifyPin(string $pin): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if (Hash::check($pin, $this->pin_hash)) {
            $this->update(['failed_pin_attempts' => 0]);
            return true;
        }

        $this->increment('failed_pin_attempts');

        if ($this->failed_pin_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
        }

        return false;
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until > now();
    }

    public function hasPin(): bool
    {
        return !empty($this->pin_hash);
    }

    public function canAfford(float $amount): bool
    {
        return $this->balance >= $amount && $this->is_active;
    }

    public function credit(float $amount, string $description = null): WalletTransaction
    {
        $balanceBefore = $this->balance;
        $this->increment('balance', $amount);
        $this->refresh();

        return $this->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $this->user_id,
            'type' => 'deposit',
            'amount' => $amount,
            'fee' => 0,
            'balance_before' => $balanceBefore,
            'balance_after' => $this->balance,
            'status' => 'completed',
            'description' => $description,
            'completed_at' => now(),
        ]);
    }

    public function debit(float $amount, float $fee = 0, string $description = null): ?WalletTransaction
    {
        $total = $amount + $fee;
        if (!$this->canAfford($total)) {
            return null;
        }

        $balanceBefore = $this->balance;
        $this->decrement('balance', $total);
        $this->refresh();

        return $this->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $this->user_id,
            'type' => 'withdrawal',
            'amount' => $amount,
            'fee' => $fee,
            'balance_before' => $balanceBefore,
            'balance_after' => $this->balance,
            'status' => 'completed',
            'description' => $description,
            'completed_at' => now(),
        ]);
    }

    public static function getOrCreate(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0, 'pending_balance' => 0, 'currency' => 'TZS', 'is_active' => true]
        );
    }
}
