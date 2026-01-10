<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_type',
        'content',
        'background_color',
        'media_files',
        'media_metadata',
        'audio_path',
        'audio_duration',
        'audio_waveform',
        'cover_image_path',
        'music_track_id',
        'music_start_time',
        'original_audio_volume',
        'music_volume',
        'video_speed',
        'text_overlays',
        'video_filter',
        'privacy',
        'location_name',
        'location_lat',
        'location_lng',
        'tagged_users',
        'scheduled_at',
        'title',
        'last_edited_at',
        'auto_save_version',
    ];

    protected $casts = [
        'media_files' => 'array',
        'media_metadata' => 'array',
        'audio_waveform' => 'array',
        'text_overlays' => 'array',
        'tagged_users' => 'array',
        'original_audio_volume' => 'decimal:2',
        'music_volume' => 'decimal:2',
        'video_speed' => 'decimal:2',
        'location_lat' => 'decimal:8',
        'location_lng' => 'decimal:8',
        'scheduled_at' => 'datetime',
        'last_edited_at' => 'datetime',
    ];

    // Post types
    const TYPE_TEXT = 'text';
    const TYPE_PHOTO = 'photo';
    const TYPE_VIDEO = 'video';
    const TYPE_SHORT_VIDEO = 'short_video';
    const TYPE_AUDIO = 'audio';

    /**
     * Get the user that owns the draft.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the music track for video drafts.
     */
    public function musicTrack(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class);
    }

    /**
     * Get display title for draft (auto-generate if not set).
     */
    public function getDisplayTitleAttribute(): string
    {
        if ($this->title) {
            return $this->title;
        }

        // Generate title from content or type
        if ($this->content) {
            return mb_substr($this->content, 0, 50) . (mb_strlen($this->content) > 50 ? '...' : '');
        }

        $typeLabels = [
            self::TYPE_TEXT => 'Text Post',
            self::TYPE_PHOTO => 'Photo Post',
            self::TYPE_VIDEO => 'Video Post',
            self::TYPE_SHORT_VIDEO => 'Short Video',
            self::TYPE_AUDIO => 'Audio Post',
        ];

        return $typeLabels[$this->post_type] ?? 'Draft';
    }

    /**
     * Get type icon for draft.
     */
    public function getTypeIconAttribute(): string
    {
        $icons = [
            self::TYPE_TEXT => '📝',
            self::TYPE_PHOTO => '🖼️',
            self::TYPE_VIDEO => '🎬',
            self::TYPE_SHORT_VIDEO => '📹',
            self::TYPE_AUDIO => '🎙️',
        ];

        return $icons[$this->post_type] ?? '📄';
    }

    /**
     * Check if draft has media.
     */
    public function getHasMediaAttribute(): bool
    {
        return !empty($this->media_files) || !empty($this->audio_path);
    }

    /**
     * Check if draft is scheduled.
     */
    public function getIsScheduledAttribute(): bool
    {
        return $this->scheduled_at !== null;
    }

    /**
     * Get thumbnail URL for draft preview.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        // For photo/video, use first media file
        if (!empty($this->media_files) && isset($this->media_files[0])) {
            $file = $this->media_files[0];
            if (isset($file['thumbnail'])) {
                return Storage::disk('public')->url($file['thumbnail']);
            }
            if (isset($file['path'])) {
                return Storage::disk('public')->url($file['path']);
            }
        }

        // For audio, use cover image
        if ($this->cover_image_path) {
            return Storage::disk('public')->url($this->cover_image_path);
        }

        return null;
    }

    /**
     * Increment auto-save version.
     */
    public function incrementVersion(): void
    {
        $this->auto_save_version++;
        $this->last_edited_at = now();
        $this->save();
    }

    /**
     * Convert draft to post data array.
     */
    public function toPostData(): array
    {
        return [
            'post_type' => $this->post_type,
            'content' => $this->content,
            'background_color' => $this->background_color,
            'privacy' => $this->privacy,
            'location_name' => $this->location_name,
            'location_lat' => $this->location_lat,
            'location_lng' => $this->location_lng,
            'audio_duration' => $this->audio_duration,
            'audio_waveform' => $this->audio_waveform,
            'music_track_id' => $this->music_track_id,
            'music_start_time' => $this->music_start_time,
            'original_audio_volume' => $this->original_audio_volume,
            'music_volume' => $this->music_volume,
            'video_speed' => $this->video_speed,
            'text_overlays' => $this->text_overlays,
            'video_filter' => $this->video_filter,
            'scheduled_at' => $this->scheduled_at,
        ];
    }

    /**
     * Scope to get user's drafts.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get drafts by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('post_type', $type);
    }

    /**
     * Scope to get recent drafts.
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('updated_at', 'desc');
    }

    /**
     * Scope to get scheduled drafts.
     */
    public function scopeScheduled($query)
    {
        return $query->whereNotNull('scheduled_at');
    }
}
