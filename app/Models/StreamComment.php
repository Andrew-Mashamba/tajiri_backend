<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamComment extends Model
{
    protected $fillable = [
        'stream_id',
        'user_id',
        'content',
        'is_pinned',
        'is_highlighted',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_highlighted' => 'boolean',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
