<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreatorStreak extends Model
{
    protected $fillable = [
        "user_id", "current_streak_days", "longest_streak_days", "last_post_at",
        "banked_skip_days", "is_frozen", "frozen_at", "streak_multiplier",
    ];
    protected $casts = [
        "is_frozen" => "boolean", "streak_multiplier" => "float",
        "last_post_at" => "datetime", "frozen_at" => "datetime",
    ];
    public function user() { return $this->belongsTo(User::class); }
}
