<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'is_system',
        'system_group_type',
        'system_lookup_key',
    ];

    protected $casts = [
        'rules' => 'array',
        'requires_approval' => 'boolean',
        'is_system' => 'boolean',
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

    // ==================== SYSTEM GROUP TYPES ====================
    //
    // Every hierarchy level gets: broad + Class-of-YYYY + Intake-YYYY
    //   broad   = all students/alumni ever
    //   year    = graduates of a specific year ("Class of 2024")
    //   intake  = students who started in a specific year ("Intake 2022")

    // Primary School
    const SYS_PRIMARY_SCHOOL           = 'primary_school';
    const SYS_PRIMARY_SCHOOL_YEAR      = 'primary_school_year';
    const SYS_PRIMARY_SCHOOL_INTAKE    = 'primary_school_intake';

    // Secondary School (O-Level)
    const SYS_SECONDARY_SCHOOL         = 'secondary_school';
    const SYS_SECONDARY_SCHOOL_YEAR    = 'secondary_school_year';
    const SYS_SECONDARY_SCHOOL_INTAKE  = 'secondary_school_intake';

    // A-Level School
    const SYS_ALEVEL_SCHOOL            = 'alevel_school';
    const SYS_ALEVEL_SCHOOL_YEAR       = 'alevel_school_year';
    const SYS_ALEVEL_SCHOOL_INTAKE     = 'alevel_school_intake';
    // A-Level School + Combination
    const SYS_ALEVEL_COMBINATION       = 'alevel_combination';
    const SYS_ALEVEL_COMBINATION_YEAR  = 'alevel_combination_year';   // INTIMATE
    const SYS_ALEVEL_COMBINATION_INTAKE = 'alevel_combination_intake'; // INTIMATE (current)

    // Post-Secondary Institution
    const SYS_POSTSECONDARY            = 'postsecondary';
    const SYS_POSTSECONDARY_YEAR       = 'postsecondary_year';
    const SYS_POSTSECONDARY_INTAKE     = 'postsecondary_intake';
    // Post-Secondary Department
    const SYS_POSTSEC_DEPT             = 'postsec_dept';
    const SYS_POSTSEC_DEPT_YEAR        = 'postsec_dept_year';
    const SYS_POSTSEC_DEPT_INTAKE      = 'postsec_dept_intake';
    // Post-Secondary Programme
    const SYS_POSTSEC_PROG             = 'postsec_prog';
    const SYS_POSTSEC_PROG_YEAR        = 'postsec_prog_year';
    const SYS_POSTSEC_PROG_INTAKE      = 'postsec_prog_intake';

    // University
    const SYS_UNIVERSITY               = 'university';
    const SYS_UNIVERSITY_YEAR          = 'university_year';
    const SYS_UNIVERSITY_INTAKE        = 'university_intake';
    // University College/School
    const SYS_UNI_COLLEGE              = 'uni_college';
    const SYS_UNI_COLLEGE_YEAR         = 'uni_college_year';
    const SYS_UNI_COLLEGE_INTAKE       = 'uni_college_intake';
    // University Department
    const SYS_UNI_DEPARTMENT           = 'uni_department';
    const SYS_UNI_DEPARTMENT_YEAR      = 'uni_department_year';
    const SYS_UNI_DEPARTMENT_INTAKE    = 'uni_department_intake';
    // University Programme
    const SYS_UNI_PROGRAMME            = 'uni_programme';
    const SYS_UNI_PROGRAMME_YEAR       = 'uni_programme_year';     // INTIMATE
    const SYS_UNI_PROGRAMME_INTAKE     = 'uni_programme_intake';   // INTIMATE (current)

    // Location
    const SYS_REGION                   = 'region';
    const SYS_DISTRICT                 = 'district';
    const SYS_WARD                     = 'ward';
    const SYS_STREET                   = 'street';

    // Employer
    const SYS_EMPLOYER                 = 'employer';

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
     * Get the linked conversation for group chat.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /**
     * Get or create the linked conversation for this group.
     */
    public function getOrCreateConversation(): Conversation
    {
        $conversation = $this->conversation;

        if ($conversation) {
            return $conversation;
        }

        return Conversation::createForGroup($this);
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

    /**
     * Scope for system-managed groups.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope for user-created groups (not system).
     */
    public function scopeUserCreated($query)
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope for system groups by type.
     */
    public function scopeOfSystemType($query, string $type)
    {
        return $query->where('is_system', true)->where('system_group_type', $type);
    }

    /**
     * Find a system group by its lookup key.
     */
    public static function findByLookupKey(string $key): ?self
    {
        return static::where('system_lookup_key', $key)->first();
    }
}
