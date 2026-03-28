<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdRevenueShare extends Model
{
    protected $fillable = [
        'creator_id', 'campaign_id', 'post_id', 'impressions',
        'revenue_earned', 'creator_share', 'platform_share',
        'period', 'status', 'paid_at',
    ];

    protected $casts = [
        'impressions' => 'integer',
        'revenue_earned' => 'float',
        'creator_share' => 'float',
        'platform_share' => 'float',
        'paid_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function campaign()
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }
}
