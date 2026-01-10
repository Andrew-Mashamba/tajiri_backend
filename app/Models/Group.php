<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Group extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_photo_path',
        'privacy',
        'creator_id',
        'members_count',
        'posts_count',
        'rules',
        'requires_approval',
    ];

    protected $casts = [
        'rules' => 'array',
        'requires_approval' => 'boolean',
        'members_count' => 'integer',
        'posts_count' => 'integer',
    ];

    /**
     * Privacy levels
     */
    const PRIVACY_PUBLIC = 'public';
    const PRIVACY_PRIVATE = 'private';
    const PRIVACY_SECRET = 'secret';

    /**
     * Member roles
     */
    const ROLE_ADMIN = 'admin';
    const ROLE_MODERATOR = 'moderator';
    const ROLE_MEMBER = 'member';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->slug)) {
                $group->slug = Str::slug($group->name) . '-' . Str::random(6);
            }
        });
    }

    /**
     * Get the creator of the group.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    /**
     * Get group members.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'group_members', 'group_id', 'user_id')
            ->withPivot(['role', 'status', 'joined_at', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Get approved members.
     */
    public function approvedMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'approved');
    }

    /**
     * Get pending members.
     */
    public function pendingMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'pending');
    }

    /**
     * Get admins.
     */
    public function admins(): BelongsToMany
    {
        return $this->approvedMembers()->wherePivot('role', self::ROLE_ADMIN);
    }

    /**
     * Get moderators.
     */
    public function moderators(): BelongsToMany
    {
        return $this->approvedMembers()->wherePivot('role', self::ROLE_MODERATOR);
    }

    /**
     * Get group posts.
     */
    public function groupPosts(): HasMany
    {
        return $this->hasMany(GroupPost::class);
    }

    /**
     * Get posts in the group.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'group_posts', 'group_id', 'post_id')
            ->withPivot(['is_pinned', 'is_announcement'])
            ->withTimestamps()
            ->orderByPivot('is_pinned', 'desc')
            ->orderBy('posts.created_at', 'desc');
    }

    /**
     * Get group invitations.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(GroupInvitation::class);
    }

    /**
     * Get events in the group.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get polls in the group.
     */
    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class);
    }

    /**
     * Get cover photo URL.
     */
    public function getCoverPhotoUrlAttribute(): ?string
    {
        return $this->cover_photo_path ? asset('storage/' . $this->cover_photo_path) : null;
    }

    /**
     * Check if user is a member.
     */
    public function isMember(int $userId): bool
    {
        return $this->approvedMembers()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admins()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Check if user is a moderator.
     */
    public function isModerator(int $userId): bool
    {
        return $this->moderators()->where('user_profiles.id', $userId)->exists();
    }

    /**
     * Check if user can manage the group.
     */
    public function canManage(int $userId): bool
    {
        return $this->isAdmin($userId) || $this->isModerator($userId);
    }

    /**
     * Get user's role in the group.
     */
    public function getUserRole(int $userId): ?string
    {
        $member = $this->members()->where('user_profiles.id', $userId)->first();
        return $member?->pivot?->role;
    }

    /**
     * Increment members count.
     */
    public function incrementMembers(): void
    {
        $this->increment('members_count');
    }

    /**
     * Decrement members count.
     */
    public function decrementMembers(): void
    {
        $this->decrement('members_count');
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
     * Scope for public groups.
     */
    public function scopePublic($query)
    {
        return $query->where('privacy', self::PRIVACY_PUBLIC);
    }

    /**
     * Scope for discoverable groups (public and private, not secret).
     */
    public function scopeDiscoverable($query)
    {
        return $query->whereIn('privacy', [self::PRIVACY_PUBLIC, self::PRIVACY_PRIVATE]);
    }
}
