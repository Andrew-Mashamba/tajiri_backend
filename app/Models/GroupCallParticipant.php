<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCallParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_call_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'left_at',
        'invited_at',
        'is_muted',
        'is_video_off',
        'hand_raised_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'invited_at' => 'datetime',
        'is_muted' => 'boolean',
        'is_video_off' => 'boolean',
        'hand_raised_at' => 'datetime',
    ];

    const STATUS_INVITED = 'invited';
    const STATUS_RINGING = 'ringing';
    const STATUS_JOINED = 'joined';
    const STATUS_LEFT = 'left';
    const STATUS_DECLINED = 'declined';

    public function groupCall(): BelongsTo
    {
        return $this->belongsTo(GroupCall::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function toggleMute(): void
    {
        $this->update(['is_muted' => !$this->is_muted]);
    }

    public function toggleVideo(): void
    {
        $this->update(['is_video_off' => !$this->is_video_off]);
    }
}
