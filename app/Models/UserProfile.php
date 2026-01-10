<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        // Bio
        'first_name',
        'last_name',
        'username',
        'date_of_birth',
        'gender',
        'phone_number',
        'is_phone_verified',
        'bio',
        'profile_photo_path',
        'cover_photo_path',
        'interests',
        'relationship_status',
        'friends_count',
        'posts_count',
        'photos_count',
        'last_active_at',

        // Location
        'region_id',
        'region_name',
        'district_id',
        'district_name',
        'ward_id',
        'ward_name',
        'street_id',
        'street_name',

        // Primary School
        'primary_school_id',
        'primary_school_code',
        'primary_school_name',
        'primary_school_type',
        'primary_graduation_year',

        // Secondary School
        'secondary_school_id',
        'secondary_school_code',
        'secondary_school_name',
        'secondary_school_type',
        'secondary_graduation_year',

        // A-Level
        'alevel_school_id',
        'alevel_school_code',
        'alevel_school_name',
        'alevel_school_type',
        'alevel_graduation_year',
        'alevel_combination_code',
        'alevel_combination_name',
        'alevel_subjects',

        // Post-Secondary
        'postsecondary_id',
        'postsecondary_code',
        'postsecondary_name',
        'postsecondary_type',
        'postsecondary_graduation_year',

        // University
        'university_id',
        'university_code',
        'university_name',
        'programme_id',
        'programme_name',
        'degree_level',
        'university_graduation_year',
        'is_current_student',

        // Employer
        'employer_id',
        'employer_code',
        'employer_name',
        'employer_sector',
        'employer_ownership',
        'is_custom_employer',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_phone_verified' => 'boolean',
        'is_current_student' => 'boolean',
        'is_custom_employer' => 'boolean',
        'alevel_subjects' => 'array',
        'interests' => 'array',
        'friends_count' => 'integer',
        'posts_count' => 'integer',
        'photos_count' => 'integer',
        'last_active_at' => 'datetime',
    ];

    /**
     * Relationship status options
     */
    const STATUS_SINGLE = 'single';
    const STATUS_IN_RELATIONSHIP = 'in_relationship';
    const STATUS_ENGAGED = 'engaged';
    const STATUS_MARRIED = 'married';
    const STATUS_COMPLICATED = 'complicated';
    const STATUS_DIVORCED = 'divorced';
    const STATUS_WIDOWED = 'widowed';

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
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
     * Get user's posts.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get user's photos.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'user_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get user's photo albums.
     */
    public function albums(): HasMany
    {
        return $this->hasMany(PhotoAlbum::class, 'user_id');
    }

    /**
     * Get user's comments.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    /**
     * Get friendships initiated by user.
     */
    public function friendshipsInitiated(): HasMany
    {
        return $this->hasMany(Friendship::class, 'user_id');
    }

    /**
     * Get friendships received by user.
     */
    public function friendshipsReceived(): HasMany
    {
        return $this->hasMany(Friendship::class, 'friend_id');
    }

    /**
     * Get all accepted friends.
     */
    public function friends()
    {
        $initiated = $this->friendshipsInitiated()
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->with('friend')
            ->get()
            ->pluck('friend');

        $received = $this->friendshipsReceived()
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->with('user')
            ->get()
            ->pluck('user');

        return $initiated->merge($received);
    }

    /**
     * Get pending friend requests received.
     */
    public function pendingFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'friend_id')
            ->where('status', Friendship::STATUS_PENDING);
    }

    /**
     * Get conversations.
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants', 'user_id', 'conversation_id')
            ->withPivot(['is_admin', 'last_read_at', 'unread_count', 'is_muted'])
            ->withTimestamps()
            ->orderBy('last_message_at', 'desc');
    }

    /**
     * Get messages sent by user.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Check if user is friends with another user.
     */
    public function isFriendsWith(int $userId): bool
    {
        return Friendship::areFriends($this->id, $userId);
    }

    /**
     * Get friendship status with another user.
     */
    public function getFriendshipStatus(int $userId): ?string
    {
        $friendship = Friendship::getBetween($this->id, $userId);
        return $friendship?->status;
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
     * Increment friends count.
     */
    public function incrementFriends(): void
    {
        $this->increment('friends_count');
    }

    /**
     * Decrement friends count.
     */
    public function decrementFriends(): void
    {
        $this->decrement('friends_count');
    }

    /**
     * Update last active timestamp.
     */
    public function touch($attribute = null): bool
    {
        $this->last_active_at = now();
        return parent::touch($attribute);
    }

    /**
     * Get friend IDs for efficient querying.
     */
    public function getFriendIds(): array
    {
        $initiated = $this->friendshipsInitiated()
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->pluck('friend_id')
            ->toArray();

        $received = $this->friendshipsReceived()
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->pluck('user_id')
            ->toArray();

        return array_merge($initiated, $received);
    }
}
