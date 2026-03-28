<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendingDigest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'headline_sw', 'headline_en', 'stories', 'mood',
        'generated_at', 'valid_until',
    ];

    protected $casts = [
        'stories' => 'array',
        'generated_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::where('valid_until', '>', now())
            ->orderBy('generated_at', 'desc')
            ->first();
    }
}
