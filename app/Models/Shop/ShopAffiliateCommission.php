<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAffiliateCommission extends Model
{
    protected $table = 'shop_affiliate_commissions';

    protected $fillable = [
        'link_id',
        'referrer_user_id',
        'order_id',
        'amount_tzs',
        'status',
    ];

    protected $casts = [
        'amount_tzs' => 'decimal:2',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(ShopAffiliateLink::class, 'link_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'referrer_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
