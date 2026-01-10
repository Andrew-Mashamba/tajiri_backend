<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileMoneyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'phone_number',
        'account_name',
        'is_verified',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    const PROVIDER_MPESA = 'mpesa';
    const PROVIDER_TIGOPESA = 'tigopesa';
    const PROVIDER_AIRTELMONEY = 'airtelmoney';
    const PROVIDER_HALOPESA = 'halopesa';

    public static function getProviders(): array
    {
        return [
            self::PROVIDER_MPESA => 'M-Pesa',
            self::PROVIDER_TIGOPESA => 'Tigo Pesa',
            self::PROVIDER_AIRTELMONEY => 'Airtel Money',
            self::PROVIDER_HALOPESA => 'Halo Pesa',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function verify(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    public function makePrimary(): void
    {
        // Remove primary from other accounts
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);
    }

    public function getProviderNameAttribute(): string
    {
        return self::getProviders()[$this->provider] ?? $this->provider;
    }

    public function getMaskedPhoneAttribute(): string
    {
        $phone = $this->phone_number;
        $length = strlen($phone);
        if ($length <= 4) return $phone;

        return substr($phone, 0, 3) . str_repeat('*', $length - 6) . substr($phone, -3);
    }
}
