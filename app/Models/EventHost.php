<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventHost extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'page_id',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Get the event.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the user host.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the page host.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
