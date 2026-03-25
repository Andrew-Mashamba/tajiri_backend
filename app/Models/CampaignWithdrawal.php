<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignWithdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'amount', 'currency', 'status',
        'destination_type', 'destination_details',
        'rejection_reason', 'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'destination_details' => 'array',
        'processed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
