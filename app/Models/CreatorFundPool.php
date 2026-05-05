<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreatorFundPool extends Model
{
    protected $fillable = ["total_amount", "currency", "month", "min_followers", "min_posts", "min_views", "is_distributed", "distributed_at"];
    protected $casts = ["total_amount" => "decimal:2", "is_distributed" => "boolean", "distributed_at" => "datetime"];
    public function payouts() { return $this->hasMany(CreatorFundPayout::class, "pool_id"); }
}
