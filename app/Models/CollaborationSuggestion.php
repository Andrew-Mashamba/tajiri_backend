<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborationSuggestion extends Model
{
    protected $fillable = [
        'user_id', 'suggested_user_id', 'reason',
        'compatibility_score', 'status',
    ];

    protected $casts = [
        'compatibility_score' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function suggestedUser()
    {
        return $this->belongsTo(User::class, 'suggested_user_id');
    }
}
