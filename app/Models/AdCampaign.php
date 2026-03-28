<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCampaign extends Model
{
    protected $fillable = [
        'advertiser_user_id', 'name', 'status', 'budget', 'spent',
        'cpm_rate', 'target_regions', 'target_interests',
        'starts_at', 'ends_at',
    ];

    protected $casts = [
        'budget' => 'float',
        'spent' => 'float',
        'cpm_rate' => 'float',
        'target_regions' => 'array',
        'target_interests' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function revenueShares()
    {
        return $this->hasMany(AdRevenueShare::class, 'campaign_id');
    }
}
