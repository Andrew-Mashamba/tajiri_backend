<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClipShare extends Model
{
    protected $fillable = [
        'clip_id',
        'user_id',
        'platform',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
