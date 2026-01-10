<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'media_type',
        'file_path',
        'thumbnail_path',
        'original_filename',
        'file_size',
        'width',
        'height',
        'duration',
        'order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'order' => 'integer',
    ];

    /**
     * Media types
     */
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_DOCUMENT = 'document';

    /**
     * Get the post this media belongs to.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
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
     * Check if media is an image.
     */
    public function isImage(): bool
    {
        return $this->media_type === self::TYPE_IMAGE;
    }

    /**
     * Check if media is a video.
     */
    public function isVideo(): bool
    {
        return $this->media_type === self::TYPE_VIDEO;
    }
}
