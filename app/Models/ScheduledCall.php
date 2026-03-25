<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'type',
        'scheduled_at',
        'title',
        'reminder_sent_at',
        'started_call_id',
        'cancelled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    const TYPE_VOICE = 'voice';
    const TYPE_VIDEO = 'video';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'creator_id');
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(ScheduledCallInvitee::class);
    }

    public function startedCall(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'started_call_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isStarted(): bool
    {
        return $this->started_call_id !== null;
    }

    public function isPending(): bool
    {
        return !$this->isCancelled() && !$this->isStarted();
    }
}
