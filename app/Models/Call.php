<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id',
        'caller_id',
        'callee_id',
        'type',
        'status',
        'started_at',
        'answered_at',
        'ended_at',
        'duration',
        'end_reason',
        'quality_metrics',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration' => 'integer',
        'quality_metrics' => 'array',
    ];

    const TYPE_VOICE = 'voice';
    const TYPE_VIDEO = 'video';

    const STATUS_PENDING = 'pending';
    const STATUS_RINGING = 'ringing';
    const STATUS_ANSWERED = 'answered';
    const STATUS_ENDED = 'ended';
    const STATUS_MISSED = 'missed';
    const STATUS_DECLINED = 'declined';
    const STATUS_BUSY = 'busy';

    const END_COMPLETED = 'completed';
    const END_MISSED = 'missed';
    const END_DECLINED = 'declined';
    const END_BUSY = 'busy';
    const END_NETWORK_ERROR = 'network_error';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($call) {
            if (empty($call->call_id)) {
                $call->call_id = 'call_' . Str::uuid();
            }
            if (empty($call->started_at)) {
                $call->started_at = now();
            }
        });
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'callee_id');
    }

    public function answer(): void
    {
        $this->update([
            'status' => self::STATUS_ANSWERED,
            'answered_at' => now(),
        ]);
    }

    public function end(string $reason = self::END_COMPLETED): void
    {
        $endedAt = now();
        $duration = $this->answered_at
            ? $endedAt->diffInSeconds($this->answered_at)
            : 0;

        $this->update([
            'status' => self::STATUS_ENDED,
            'ended_at' => $endedAt,
            'duration' => $duration,
            'end_reason' => $reason,
        ]);

        // Create call logs for both users
        $this->createCallLogs();
    }

    public function decline(): void
    {
        $this->update([
            'status' => self::STATUS_DECLINED,
            'ended_at' => now(),
            'end_reason' => self::END_DECLINED,
        ]);
        $this->createCallLogs();
    }

    public function miss(): void
    {
        $this->update([
            'status' => self::STATUS_MISSED,
            'ended_at' => now(),
            'end_reason' => self::END_MISSED,
        ]);
        $this->createCallLogs();
    }

    protected function createCallLogs(): void
    {
        // Caller log
        CallLog::create([
            'user_id' => $this->caller_id,
            'call_id' => $this->id,
            'other_user_id' => $this->callee_id,
            'type' => $this->type,
            'direction' => 'outgoing',
            'status' => $this->status === self::STATUS_ENDED ? 'answered' : $this->status,
            'duration' => $this->duration,
            'call_time' => $this->started_at,
        ]);

        // Callee log
        CallLog::create([
            'user_id' => $this->callee_id,
            'call_id' => $this->id,
            'other_user_id' => $this->caller_id,
            'type' => $this->type,
            'direction' => 'incoming',
            'status' => $this->status === self::STATUS_ENDED ? 'answered' : $this->status,
            'duration' => $this->duration,
            'call_time' => $this->started_at,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RINGING, self::STATUS_ANSWERED]);
    }
}
