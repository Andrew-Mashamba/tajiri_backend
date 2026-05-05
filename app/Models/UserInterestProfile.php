<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInterestProfile extends Model
{
    protected $fillable = [
        'user_id',
        'interest_vector',
        'top_creators',
        'top_hashtags',
        'preferred_formats',
        'active_hours',
        'updated_at',
    ];

    protected $casts = [
        'interest_vector' => 'array',
        'top_creators' => 'array',
        'top_hashtags' => 'array',
        'preferred_formats' => 'array',
        'active_hours' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
