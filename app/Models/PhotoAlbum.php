<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotoAlbum extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'privacy',
        'cover_photo_id',
        'photos_count',
        'is_system_album',
        'system_album_type',
    ];

    protected $casts = [
        'photos_count' => 'integer',
        'is_system_album' => 'boolean',
    ];

    /**
     * Privacy levels
     */
    const PRIVACY_PUBLIC = 'public';
    const PRIVACY_FRIENDS = 'friends';
    const PRIVACY_PRIVATE = 'private';

    /**
     * System album types
     */
    const SYSTEM_PROFILE = 'profile_photos';
    const SYSTEM_COVER = 'cover_photos';
    const SYSTEM_WALL = 'wall_photos';

    /**
     * Get the user who owns the album.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get photos in this album.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'album_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the cover photo.
     */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'cover_photo_id');
    }

    /**
     * Increment photos count.
     */
    public function incrementPhotos(): void
    {
        $this->increment('photos_count');
    }

    /**
     * Decrement photos count.
     */
    public function decrementPhotos(): void
    {
        $this->decrement('photos_count');
    }

    /**
     * Check if album is a system album.
     */
    public function isSystemAlbum(): bool
    {
        return $this->is_system_album;
    }

    /**
     * Get or create system album for user.
     */
    public static function getOrCreateSystemAlbum(int $userId, string $type): self
    {
        $names = [
            self::SYSTEM_PROFILE => 'Picha za Wasifu',
            self::SYSTEM_COVER => 'Picha za Jalada',
            self::SYSTEM_WALL => 'Picha za Ukuta',
        ];

        return self::firstOrCreate(
            [
                'user_id' => $userId,
                'system_album_type' => $type,
            ],
            [
                'name' => $names[$type] ?? 'Album',
                'is_system_album' => true,
                'privacy' => self::PRIVACY_PUBLIC,
            ]
        );
    }
}
