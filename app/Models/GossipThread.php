<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GossipThread extends Model
{
    protected $fillable = [
        "seed_post_id", "title_key", "title_slots", "category",
        "velocity_score", "post_count", "participant_count", "status",
        "geographic_scope", "latitude", "longitude", "cooling_since",
    ];

    protected $casts = [
        "title_slots" => "array",
        "velocity_score" => "decimal:2",
        "cooling_since" => "datetime",
    ];

    public function seedPost()
    {
        return $this->belongsTo(Post::class, "seed_post_id");
    }

    public function threadPosts()
    {
        return $this->hasMany(GossipThreadPost::class, "thread_id");
    }

    public function posts()
    {
        return $this->belongsToMany(Post::class, "gossip_thread_posts", "thread_id", "post_id")
            ->withPivot("relevance_score", "added_at")
            ->orderByPivot("relevance_score", "desc");
    }

    public function template()
    {
        return $this->belongsTo(ThreadTitleTemplate::class, "title_key", "key");
    }

    public function getResolvedTitleEnAttribute(): string
    {
        $tpl = $this->template;
        if (!$tpl) return "Trending";
        $title = $tpl->template_en;
        $slots = $this->title_slots ?? [];
        foreach ($slots as $key => $val) {
            $title = str_replace("{{$key}}", $val, $title);
        }
        return $title;
    }

    public function getResolvedTitleSwAttribute(): string
    {
        $tpl = $this->template;
        if (!$tpl) return "Vinavyoongezeka";
        $title = $tpl->template_sw;
        $slots = $this->title_slots ?? [];
        foreach ($slots as $key => $val) {
            $title = str_replace("{{$key}}", $val, $title);
        }
        return $title;
    }
}
