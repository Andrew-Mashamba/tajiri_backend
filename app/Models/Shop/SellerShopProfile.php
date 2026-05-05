<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerShopProfile extends Model
{
    protected $table = 'seller_shop_profiles';

    protected $fillable = [
        'user_id',
        'store_name',
        'headline',
        'description',
        'banner_image_url',
        'logo_url',
        'accent_hex',
        'social_links',
        'settings',
    ];

    protected $casts = [
        'social_links' => 'array',
        'settings' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
