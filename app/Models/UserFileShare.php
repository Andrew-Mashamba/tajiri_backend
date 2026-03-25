<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFileShare extends Model
{
    protected $fillable = [
        'file_id',
        'shared_by_user_id',
        'shared_with_user_id',
        'permission',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(UserFile::class, 'file_id');
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    public function sharedWith(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }
}
