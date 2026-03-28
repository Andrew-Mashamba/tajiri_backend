<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEngagementLevel extends Model
{
    protected $fillable = [
        'user_id',
        'level',
        'weekly_actions',
        'streak_days',
        'days_active_14d',
        'sessions_14d',
        'posts_created_14d',
    ];

    protected $casts = [
        'weekly_actions' => 'integer',
        'streak_days' => 'integer',
        'days_active_14d' => 'integer',
        'sessions_14d' => 'integer',
        'posts_created_14d' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
