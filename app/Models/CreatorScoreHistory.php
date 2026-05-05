<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CreatorScoreHistory extends Model
{
    public $timestamps = false;
    protected $table = "creator_score_history";
    protected $fillable = ["user_id", "score", "tier", "snapshot_date", "component_scores", "created_at"];
    protected $casts = ["score" => "float", "component_scores" => "array", "snapshot_date" => "date"];
    public function user() { return $this->belongsTo(User::class); }
}
