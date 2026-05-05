<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreatorFundPayout extends Model
{
    protected $fillable = ["pool_id", "user_id", "base_score", "tier_multiplier", "streak_multiplier", "community_multiplier", "virality_multiplier", "effective_multiplier", "final_score", "payout_amount", "payout_currency", "status", "paid_at"];
    protected $casts = ["base_score" => "decimal:4", "tier_multiplier" => "decimal:2", "streak_multiplier" => "decimal:2", "community_multiplier" => "decimal:2", "virality_multiplier" => "decimal:2", "effective_multiplier" => "decimal:2", "final_score" => "decimal:4", "payout_amount" => "decimal:2", "paid_at" => "datetime"];
    public function pool() { return $this->belongsTo(CreatorFundPool::class, "pool_id"); }
    public function user() { return $this->belongsTo(User::class); }
}
