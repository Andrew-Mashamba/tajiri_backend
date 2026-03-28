<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorCoaching extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'creator_id', 'advice', 'week_start', 'generated_at',
    ];

    protected $casts = [
        'advice' => 'array',
        'week_start' => 'date',
        'generated_at' => 'datetime',
    ];
}
