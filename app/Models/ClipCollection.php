<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClipCollection extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'privacy',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
