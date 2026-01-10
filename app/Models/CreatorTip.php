<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorTip extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'creator_id',
        'amount',
        'message',
        'payment_method',
        'transaction_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'sender_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }
}
