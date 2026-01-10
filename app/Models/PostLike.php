<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'reaction_type',
    ];

    /**
     * Reaction types
     */
    const REACTION_LIKE = 'like';
    const REACTION_LOVE = 'love';
    const REACTION_HAHA = 'haha';
    const REACTION_WOW = 'wow';
    const REACTION_SAD = 'sad';
    const REACTION_ANGRY = 'angry';

    /**
     * Get the post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the user who liked.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
