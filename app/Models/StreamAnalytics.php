<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamAnalytics extends Model
{
    protected $fillable = [
        'stream_id',
        'timestamp',
        'viewers_count',
        'engagement_rate',
        'data',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'engagement_rate' => 'decimal:2',
        'data' => 'array',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }
}
