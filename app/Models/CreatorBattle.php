<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorBattle extends Model
{
    protected $fillable = [
        'creator_a_id', 'creator_b_id',
        'post_a_id', 'post_b_id',
        'topic', 'status', 'votes_a', 'votes_b', 'ends_at',
    ];

    protected $casts = [
        'votes_a' => 'integer',
        'votes_b' => 'integer',
        'ends_at' => 'datetime',
    ];

    public function creatorA()
    {
        return $this->belongsTo(User::class, 'creator_a_id');
    }

    public function creatorB()
    {
        return $this->belongsTo(User::class, 'creator_b_id');
    }

    public function postA()
    {
        return $this->belongsTo(Post::class, 'post_a_id');
    }

    public function postB()
    {
        return $this->belongsTo(Post::class, 'post_b_id');
    }

    public function votes()
    {
        return $this->hasMany(CreatorBattleVote::class, 'battle_id');
    }
}
