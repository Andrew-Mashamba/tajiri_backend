<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChunkUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'upload_id',
        'user_id',
        'original_filename',
        'mime_type',
        'total_size',
        'total_chunks',
        'chunk_size',
        'uploaded_chunks',
        'uploaded_bytes',
        'completed_chunks',
        'temp_directory',
        'final_path',
        'caption',
        'hashtags',
        'mentions',
        'location_name',
        'latitude',
        'longitude',
        'privacy',
        'allow_comments',
        'allow_duet',
        'allow_stitch',
        'allow_download',
        'music_id',
        'music_start',
        'original_clip_id',
        'clip_type',
        'status',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'mentions' => 'array',
        'completed_chunks' => 'array',
        'allow_comments' => 'boolean',
        'allow_duet' => 'boolean',
        'allow_stitch' => 'boolean',
        'allow_download' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a unique upload ID
     */
    public static function generateUploadId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get the user that owns the upload
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the music track for this upload
     */
    public function music(): BelongsTo
    {
        return $this->belongsTo(MusicTrack::class, 'music_id');
    }

    /**
     * Get the original clip (for duets/stitches)
     */
    public function originalClip(): BelongsTo
    {
        return $this->belongsTo(Clip::class, 'original_clip_id');
    }

    /**
     * Check if a specific chunk has been uploaded
     */
    public function isChunkUploaded(int $chunkNumber): bool
    {
        $completed = $this->completed_chunks ?? [];
        return in_array($chunkNumber, $completed);
    }

    /**
     * Mark a chunk as uploaded
     */
    public function markChunkUploaded(int $chunkNumber, int $bytesUploaded): void
    {
        $completed = $this->completed_chunks ?? [];

        if (!in_array($chunkNumber, $completed)) {
            $completed[] = $chunkNumber;
            sort($completed);

            $this->update([
                'completed_chunks' => $completed,
                'uploaded_chunks' => count($completed),
                'uploaded_bytes' => $this->uploaded_bytes + $bytesUploaded,
            ]);
        }
    }

    /**
     * Get the list of missing chunks
     */
    public function getMissingChunks(): array
    {
        $allChunks = range(0, $this->total_chunks - 1);
        $completed = $this->completed_chunks ?? [];
        return array_values(array_diff($allChunks, $completed));
    }

    /**
     * Check if all chunks have been uploaded
     */
    public function isComplete(): bool
    {
        return $this->uploaded_chunks >= $this->total_chunks;
    }

    /**
     * Get upload progress as percentage
     */
    public function getProgressAttribute(): float
    {
        if ($this->total_chunks == 0) return 0;
        return round(($this->uploaded_chunks / $this->total_chunks) * 100, 2);
    }

    /**
     * Scope for pending uploads
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for uploading status
     */
    public function scopeUploading($query)
    {
        return $query->where('status', 'uploading');
    }

    /**
     * Scope for expired uploads (for cleanup)
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now())
            ->whereIn('status', ['pending', 'uploading']);
    }

    /**
     * Scope for user's resumable uploads
     */
    public function scopeResumable($query, int $userId)
    {
        return $query->where('user_id', $userId)
            ->whereIn('status', ['pending', 'uploading'])
            ->where('expires_at', '>', now())
            ->orderByDesc('updated_at');
    }
}
