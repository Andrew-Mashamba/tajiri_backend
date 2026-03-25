<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDonation extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'donor_id', 'amount', 'currency',
        'is_anonymous', 'message', 'donor_name', 'donor_avatar_url',
        'payment_ref', 'status', 'payment_method', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_anonymous' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'donor_id');
    }
}
