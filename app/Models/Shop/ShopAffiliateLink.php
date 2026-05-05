<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopAffiliateLink extends Model
{
    protected $table = 'shop_affiliate_links';

    protected $fillable = [
        'user_id',
        'code',
        'label',
        'commission_percent',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (ShopAffiliateLink $link) {
            if (empty($link->code)) {
                $link->code = Str::upper(Str::random(8));
                while (static::where('code', $link->code)->exists()) {
                    $link->code = Str::upper(Str::random(8));
                }
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ShopAffiliateCommission::class, 'link_id');
    }
}
