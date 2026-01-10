<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClipComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'clip_id',
        'user_id',
        'parent_id',
        'content',
        'likes_count',
        'replies_count',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ClipComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ClipComment::class, 'parent_id');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'clip_comment_likes', 'comment_id', 'user_id')->withTimestamps();
    }

    public function isLikedBy(int $userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }
}
