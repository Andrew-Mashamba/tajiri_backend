<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'invited_by',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /**
     * Status options
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_BANNED = 'banned';

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get the user who invited this member.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'invited_by');
    }
}
