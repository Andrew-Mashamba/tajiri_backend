<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'post_id',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    /**
     * Get the event.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
