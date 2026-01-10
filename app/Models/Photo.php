<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'album_id',
        'file_path',
        'thumbnail_path',
        'width',
        'height',
        'file_size',
        'caption',
        'location_name',
        'likes_count',
        'comments_count',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
    ];

    /**
     * Get the user who uploaded the photo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the album this photo belongs to.
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(PhotoAlbum::class, 'album_id');
    }

    /**
     * Get full URL for file.
     */
    public function getFileUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    /**
     * Get full URL for thumbnail.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? asset('storage/' . $this->thumbnail_path) : null;
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
}
