<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorBattleVote extends Model
{
    protected $fillable = ['battle_id', 'user_id', 'side'];

    public function battle()
    {
        return $this->belongsTo(CreatorBattle::class, 'battle_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
