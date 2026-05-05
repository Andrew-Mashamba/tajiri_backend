<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSellerBan extends Model
{
    protected $table = 'shop_seller_bans';

    protected $fillable = [
        'seller_user_id',
        'reason',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'seller_user_id');
    }
}
