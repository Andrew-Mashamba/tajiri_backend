<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualGift extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon_path',
        'animation_path',
        'price',
        'creator_share',
        'is_active',
        'order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'creator_share' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }
}
