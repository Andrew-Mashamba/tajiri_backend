<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAdCampaign extends Model
{
    protected $table = 'shop_ad_campaigns';

    protected $fillable = [
        'user_id',
        'name',
        'status',
        'daily_budget_tzs',
        'total_budget_tzs',
        'start_at',
        'end_at',
        'targeting',
        'creative',
    ];

    protected $casts = [
        'daily_budget_tzs' => 'decimal:2',
        'total_budget_tzs' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'targeting' => 'array',
        'creative' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
