<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_photo_path',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'timezone',
        'is_all_day',
        'location_name',
        'location_address',
        'latitude',
        'longitude',
        'is_online',
        'online_link',
        'privacy',
        'category',
        'creator_id',
        'group_id',
        'page_id',
        'going_count',
        'interested_count',
        'not_going_count',
        'ticket_price',
        'ticket_currency',
        'ticket_link',
        'is_recurring',
        'recurrence_rule',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_all_day' => 'boolean',
        'is_online' => 'boolean',
        'is_recurring' => 'boolean',
        'recurrence_rule' => 'array',
        'going_count' => 'integer',
        'interested_count' => 'integer',
        'not_going_count' => 'integer',
        'ticket_price' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Privacy levels
     */
    const PRIVACY_PUBLIC = 'public';
    const PRIVACY_FRIENDS = 'friends';
    const PRIVACY_PRIVATE = 'private';
    const PRIVACY_GROUP = 'group';

    /**
     * Response types
     */
    const RESPONSE_GOING = 'going';
    const RESPONSE_INTERESTED = 'interested';
    const RESPONSE_NOT_GOING = 'not_going';

    /**
     * Event categories
     */
    const CATEGORY_SOCIAL = 'social';
    const CATEGORY_BUSINESS = 'business';
    const CATEGORY_EDUCATION = 'education';
    const CATEGORY_ENTERTAINMENT = 'entertainment';
    const CATEGORY_SPORTS = 'sports';
    const CATEGORY_HEALTH = 'health';
    const CATEGORY_RELIGIOUS = 'religious';
    const CATEGORY_POLITICAL = 'political';
    const CATEGORY_OTHER = 'other';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($event) {
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->name) . '-' . Str::random(6);
            }
        });
    }

    /**
     * Get the creator of the event.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    /**
     * Get the group if event is in a group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the page if event is hosted by a page.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get event responses.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(EventResponse::class);
    }

    /**
     * Get users going to the event.
     */
    public function going(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'event_responses', 'event_id', 'user_id')
            ->wherePivot('response', self::RESPONSE_GOING)
            ->withTimestamps();
    }

    /**
     * Get users interested in the event.
     */
    public function interested(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'event_responses', 'event_id', 'user_id')
            ->wherePivot('response', self::RESPONSE_INTERESTED)
            ->withTimestamps();
    }

    /**
     * Get event hosts.
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(EventHost::class);
    }

    /**
     * Get user hosts.
     */
    public function userHosts(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'event_hosts', 'event_id', 'user_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get page hosts.
     */
    public function pageHosts(): BelongsToMany
    {
        return $this->belongsToMany(Page::class, 'event_hosts', 'event_id', 'page_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get event posts (discussions).
     */
    public function eventPosts(): HasMany
    {
        return $this->hasMany(EventPost::class);
    }

    /**
     * Get posts in the event.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'event_posts', 'event_id', 'post_id')
            ->withPivot('is_pinned')
            ->withTimestamps()
            ->orderByPivot('is_pinned', 'desc')
            ->orderBy('posts.created_at', 'desc');
    }

    /**
     * Get cover photo URL.
     */
    public function getCoverPhotoUrlAttribute(): ?string
    {
        return $this->cover_photo_path ? asset('storage/' . $this->cover_photo_path) : null;
    }

    /**
     * Get formatted start datetime.
     */
    public function getStartDatetimeAttribute(): string
    {
        if ($this->is_all_day) {
            return $this->start_date->format('Y-m-d');
        }
        return $this->start_date->format('Y-m-d') . ' ' . ($this->start_time ?? '00:00:00');
    }

    /**
     * Get user's response to this event.
     */
    public function getUserResponse(int $userId): ?string
    {
        $response = $this->responses()->where('user_id', $userId)->first();
        return $response?->response;
    }

    /**
     * Check if user is going.
     */
    public function isGoing(int $userId): bool
    {
        return $this->getUserResponse($userId) === self::RESPONSE_GOING;
    }

    /**
     * Check if user is interested.
     */
    public function isInterested(int $userId): bool
    {
        return $this->getUserResponse($userId) === self::RESPONSE_INTERESTED;
    }

    /**
     * Check if user is a host.
     */
    public function isHost(int $userId): bool
    {
        return $this->userHosts()->where('user_profiles.id', $userId)->exists() ||
               $this->creator_id === $userId;
    }

    /**
     * Update response counts based on change.
     */
    public function updateResponseCounts(?string $oldResponse, ?string $newResponse): void
    {
        if ($oldResponse) {
            $this->decrement($oldResponse . '_count');
        }
        if ($newResponse) {
            $this->increment($newResponse . '_count');
        }
    }

    /**
     * Scope for upcoming events.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now()->toDateString())
            ->orderBy('start_date')
            ->orderBy('start_time');
    }

    /**
     * Scope for past events.
     */
    public function scopePast($query)
    {
        return $query->where('start_date', '<', now()->toDateString())
            ->orderBy('start_date', 'desc');
    }

    /**
     * Scope for public events.
     */
    public function scopePublic($query)
    {
        return $query->where('privacy', self::PRIVACY_PUBLIC);
    }

    /**
     * Scope for events in date range.
     */
    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('start_date', [$start, $end]);
    }
}
