<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsoredPost extends Model
{
    protected $fillable = [
        'post_id', 'sponsor_user_id', 'creator_user_id',
        'budget', 'currency', 'status', 'tier_required',
        'impressions_target', 'impressions_delivered',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'impressions_target' => 'integer',
        'impressions_delivered' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }
}
