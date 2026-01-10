<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'inviter_id',
        'invitee_id',
        'status',
    ];

    /**
     * Status options
     */
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the inviter.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'inviter_id');
    }

    /**
     * Get the invitee.
     */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'invitee_id');
    }
}
