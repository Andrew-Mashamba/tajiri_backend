<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagePost extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'post_id',
        'posted_by',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    /**
     * Get the page.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the user who posted.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'posted_by');
    }
}
