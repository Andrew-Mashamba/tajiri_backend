<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GossipThreadPost extends Model
{
    protected $table = "gossip_thread_posts";
    protected $fillable = ["thread_id", "post_id", "relevance_score", "added_at"];
    protected $casts = ["relevance_score" => "decimal:2", "added_at" => "datetime"];

    public function thread() { return $this->belongsTo(GossipThread::class, "thread_id"); }
    public function post() { return $this->belongsTo(Post::class, "post_id"); }
}
