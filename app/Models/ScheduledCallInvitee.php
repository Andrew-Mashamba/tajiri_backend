<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledCallInvitee extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheduled_call_id',
        'user_id',
        'notified_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function scheduledCall(): BelongsTo
    {
        return $this->belongsTo(ScheduledCall::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }
}
