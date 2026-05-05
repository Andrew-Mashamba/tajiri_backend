<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEvent extends Model
{
    protected $fillable = [
        "user_id", "event_type", "post_id", "creator_id",
        "timestamp", "duration_ms", "session_id", "metadata",
    ];

    protected $casts = [
        "timestamp" => "datetime",
        "duration_ms" => "integer",
        "metadata" => "array",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
