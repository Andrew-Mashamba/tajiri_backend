<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'post_id',
        'is_pinned',
        'is_announcement',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_announcement' => 'boolean',
    ];

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
