<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreatorScore extends Model
{
    protected $fillable = [
        "user_id", "score", "tier", "community_score", "quality_score",
        "consistency_score", "tier_multiplier", "computed_at",
    ];
    protected $casts = [
        "score" => "float", "community_score" => "float",
        "quality_score" => "float", "consistency_score" => "float",
        "tier_multiplier" => "float", "computed_at" => "datetime",
    ];
    public function user() { return $this->belongsTo(User::class); }
}
