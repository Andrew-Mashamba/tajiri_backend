<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStorageQuota extends Model
{
    protected $fillable = [
        'user_id',
        'used_bytes',
        'total_bytes',
        'file_count',
        'folder_count',
    ];

    protected $casts = [
        'used_bytes' => 'integer',
        'total_bytes' => 'integer',
        'file_count' => 'integer',
        'folder_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getOrCreate(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'used_bytes' => 0,
                'total_bytes' => 5368709120, // 5GB
                'file_count' => 0,
                'folder_count' => 0,
            ]
        );
    }

    public function hasSpace(int $bytes): bool
    {
        return ($this->used_bytes + $bytes) <= $this->total_bytes;
    }

    public function addUsage(int $bytes, bool $isFolder = false): void
    {
        $this->increment('used_bytes', $bytes);
        if ($isFolder) {
            $this->increment('folder_count');
        } else {
            $this->increment('file_count');
        }
    }

    public function removeUsage(int $bytes, bool $isFolder = false): void
    {
        $this->decrement('used_bytes', min($bytes, $this->used_bytes));
        if ($isFolder) {
            $this->decrement('folder_count', min(1, $this->folder_count));
        } else {
            $this->decrement('file_count', min(1, $this->file_count));
        }
    }
}
