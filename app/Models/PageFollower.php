<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageFollower extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'user_id',
        'notifications_enabled',
    ];

    protected $casts = [
        'notifications_enabled' => 'boolean',
    ];

    /**
     * Get the page.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
