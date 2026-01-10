<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamGift extends Model
{
    protected $fillable = [
        'stream_id',
        'sender_id',
        'gift_id',
        'quantity',
        'total_value',
        'message',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'sender_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(VirtualGift::class, 'gift_id');
    }
}
