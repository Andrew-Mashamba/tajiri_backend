<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentTierHistory extends Model
{
    public $timestamps = false;

    protected $table = 'content_tier_history';

    protected $fillable = [
        'document_id', 'old_tier', 'new_tier', 'composite_score', 'changed_at',
    ];

    protected $casts = [
        'composite_score' => 'float',
        'changed_at' => 'datetime',
    ];
}
