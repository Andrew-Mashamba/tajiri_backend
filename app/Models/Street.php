<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Street extends Model
{
    protected $fillable = ['ward_id', 'name'];

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }
}
