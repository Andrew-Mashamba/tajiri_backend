<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ViewerStreak extends Model
{
    protected $fillable = [
        "user_id", "current_streak_days", "longest_streak_days",
        "last_active_date", "is_frozen", "frozen_at",
    ];
    protected $casts = [
        "is_frozen" => "boolean", "last_active_date" => "date", "frozen_at" => "datetime",
    ];
    public function user() { return $this->belongsTo(User::class); }
}
