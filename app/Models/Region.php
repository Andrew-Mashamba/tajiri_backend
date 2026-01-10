<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = ['name', 'post_code'];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
