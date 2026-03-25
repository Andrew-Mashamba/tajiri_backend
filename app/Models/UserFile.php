<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;

class UserFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'folder_id',
        'name',
        'display_name',
        'path',
        'parent_path',
        'storage_path',
        'mime_type',
        'size',
        'is_folder',
        'is_starred',
        'is_offline',
        'is_shared',
        'share_token',
        'share_expires_at',
        'thumbnail_path',
        'preview_path',
        'last_accessed_at',
    ];

    protected $casts = [
        'is_folder' => 'boolean',
        'is_starred' => 'boolean',
        'is_offline' => 'boolean',
        'is_shared' => 'boolean',
        'size' => 'integer',
        'share_expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    protected $appends = ['download_url', 'thumbnail_url', 'preview_url'];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(UserFile::class, 'folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(UserFile::class, 'folder_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(UserFileShare::class, 'file_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getDownloadUrlAttribute(): ?string
    {
        if ($this->is_folder) {
            return null;
        }
        return url("/api/files/{$this->id}/download");
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }
        return Storage::disk('local')->url($this->thumbnail_path);
    }

    public function getPreviewUrlAttribute(): ?string
    {
        if (!$this->preview_path) {
            return null;
        }
        return Storage::disk('local')->url($this->preview_path);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public static function getCategory(string $mimeType): string
    {
        if (str_contains($mimeType, 'zip') ||
            str_contains($mimeType, 'rar') ||
            str_contains($mimeType, 'tar') ||
            str_contains($mimeType, '7z')) {
            return 'archive';
        }

        if (str_contains($mimeType, 'pdf') ||
            str_contains($mimeType, 'document') ||
            str_contains($mimeType, 'text') ||
            str_contains($mimeType, 'sheet') ||
            str_contains($mimeType, 'presentation') ||
            str_contains($mimeType, 'msword') ||
            str_contains($mimeType, 'officedocument')) {
            return 'document';
        }

        return 'other';
    }

    public static function allowedMimeTypes(): array
    {
        return [
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'text/rtf',
            // Archives
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
        ];
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInFolder($query, ?int $folderId)
    {
        return $query->where('folder_id', $folderId);
    }

    public function scopeStarred($query)
    {
        return $query->where('is_starred', true);
    }

    public function scopeOffline($query)
    {
        return $query->where('is_offline', true);
    }

    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return match ($category) {
            'archive' => $query->where(function ($q) {
                $q->where('mime_type', 'ILIKE', '%zip%')
                  ->orWhere('mime_type', 'ILIKE', '%rar%')
                  ->orWhere('mime_type', 'ILIKE', '%tar%')
                  ->orWhere('mime_type', 'ILIKE', '%7z%');
            }),
            'document' => $query->where(function ($q) {
                $q->where('mime_type', 'ILIKE', '%pdf%')
                  ->orWhere('mime_type', 'ILIKE', '%document%')
                  ->orWhere('mime_type', 'ILIKE', '%text%')
                  ->orWhere('mime_type', 'ILIKE', '%sheet%')
                  ->orWhere('mime_type', 'ILIKE', '%presentation%')
                  ->orWhere('mime_type', 'ILIKE', '%msword%')
                  ->orWhere('mime_type', 'ILIKE', '%officedocument%');
            }),
            default => $query->where(function ($q) {
                $q->where('mime_type', 'NOT ILIKE', '%zip%')
                  ->where('mime_type', 'NOT ILIKE', '%rar%')
                  ->where('mime_type', 'NOT ILIKE', '%tar%')
                  ->where('mime_type', 'NOT ILIKE', '%7z%')
                  ->where('mime_type', 'NOT ILIKE', '%pdf%')
                  ->where('mime_type', 'NOT ILIKE', '%document%')
                  ->where('mime_type', 'NOT ILIKE', '%text%')
                  ->where('mime_type', 'NOT ILIKE', '%sheet%')
                  ->where('mime_type', 'NOT ILIKE', '%presentation%')
                  ->where('mime_type', 'NOT ILIKE', '%msword%')
                  ->where('mime_type', 'NOT ILIKE', '%officedocument%');
            }),
        };
    }
}
