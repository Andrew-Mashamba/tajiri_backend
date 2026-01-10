<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'subcategory',
        'description',
        'profile_photo_path',
        'cover_photo_path',
        'website',
        'phone',
        'email',
        'address',
        'latitude',
        'longitude',
        'hours',
        'social_links',
        'creator_id',
        'likes_count',
        'followers_count',
        'posts_count',
        'is_verified',
    ];

    protected $casts = [
        'hours' => 'array',
        'social_links' => 'array',
        'is_verified' => 'boolean',
        'likes_count' => 'integer',
        'followers_count' => 'integer',
        'posts_count' => 'integer',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Page categories
     */
    const CATEGORY_BUSINESS = 'business';
    const CATEGORY_BRAND = 'brand';
    const CATEGORY_COMMUNITY = 'community';
    const CATEGORY_ENTERTAINMENT = 'entertainment';
    const CATEGORY_EDUCATION = 'education';
    const CATEGORY_GOVERNMENT = 'government';
    const CATEGORY_NONPROFIT = 'nonprofit';
    const CATEGORY_HEALTH = 'health';
    const CATEGORY_NEWS = 'news';
    const CATEGORY_SPORTS = 'sports';
    const CATEGORY_OTHER = 'other';

    /**
     * Admin roles
     */
    const ROLE_ADMIN = 'admin';
    const ROLE_EDITOR = 'editor';
    const ROLE_MODERATOR = 'moderator';
    const ROLE_ANALYST = 'analyst';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            if (empty($page->slug)) {
                $page->slug = Str::slug($page->name) . '-' . Str::random(6);
            }
        });
    }

    /**
     * Get the creator of the page.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    /**
     * Get page roles (admins, editors, etc).
     */
    public function roles(): HasMany
    {
        return $this->hasMany(PageRole::class);
    }

    /**
     * Get admins.
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'page_roles', 'page_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get followers.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'page_followers', 'page_id', 'user_id')
            ->withPivot('notifications_enabled')
            ->withTimestamps();
    }

    /**
     * Get users who liked the page.
     */
    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'page_likes', 'page_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Get page posts.
     */
    public function pagePosts(): HasMany
    {
        return $this->hasMany(PagePost::class);
    }

    /**
     * Get posts on the page.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'page_posts', 'page_id', 'post_id')
            ->withPivot(['posted_by', 'is_pinned'])
            ->withTimestamps()
            ->orderByPivot('is_pinned', 'desc')
            ->orderBy('posts.created_at', 'desc');
    }

    /**
     * Get reviews.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(PageReview::class);
    }

    /**
     * Get events hosted by the page.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get profile photo URL.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path ? asset('storage/' . $this->profile_photo_path) : null;
    }

    /**
     * Get cover photo URL.
     */
    public function getCoverPhotoUrlAttribute(): ?string
    {
        return $this->cover_photo_path ? asset('storage/' . $this->cover_photo_path) : null;
    }

    /**
     * Get average rating.
     */
    public function getAverageRatingAttribute(): ?float
    {
        return $this->reviews()->avg('rating');
    }

    /**
     * Check if user follows the page.
     */
    public function isFollowedBy(int $userId): bool
    {
        return $this->followers()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Check if user liked the page.
     */
    public function isLikedBy(int $userId): bool
    {
        return $this->likedBy()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admins()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Get user's role on the page.
     */
    public function getUserRole(int $userId): ?string
    {
        $role = $this->roles()->where('user_id', $userId)->first();
        return $role?->role;
    }

    /**
     * Check if user can manage the page.
     */
    public function canManage(int $userId): bool
    {
        $role = $this->getUserRole($userId);
        return in_array($role, [self::ROLE_ADMIN, self::ROLE_EDITOR]);
    }

    /**
     * Increment likes count.
     */
    public function incrementLikes(): void
    {
        $this->increment('likes_count');
    }

    /**
     * Decrement likes count.
     */
    public function decrementLikes(): void
    {
        $this->decrement('likes_count');
    }

    /**
     * Increment followers count.
     */
    public function incrementFollowers(): void
    {
        $this->increment('followers_count');
    }

    /**
     * Decrement followers count.
     */
    public function decrementFollowers(): void
    {
        $this->decrement('followers_count');
    }

    /**
     * Increment posts count.
     */
    public function incrementPosts(): void
    {
        $this->increment('posts_count');
    }

    /**
     * Decrement posts count.
     */
    public function decrementPosts(): void
    {
        $this->decrement('posts_count');
    }

    /**
     * Scope for verified pages.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope for category.
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
