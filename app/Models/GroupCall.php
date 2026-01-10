<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GroupCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id',
        'conversation_id',
        'initiated_by',
        'type',
        'status',
        'started_at',
        'ended_at',
        'max_participants',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'max_participants' => 'integer',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_ENDED = 'ended';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($call) {
            if (empty($call->call_id)) {
                $call->call_id = 'group_call_' . Str::uuid();
            }
            if (empty($call->started_at)) {
                $call->started_at = now();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'initiated_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GroupCallParticipant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->where('status', 'joined')->whereNull('left_at');
    }

    public function addParticipant(int $userId): GroupCallParticipant
    {
        $participant = $this->participants()->updateOrCreate(
            ['user_id' => $userId],
            ['status' => 'joined', 'joined_at' => now(), 'left_at' => null]
        );

        $activeCount = $this->activeParticipants()->count();
        if ($activeCount > $this->max_participants) {
            $this->update(['max_participants' => $activeCount]);
        }

        return $participant;
    }

    public function removeParticipant(int $userId): void
    {
        $this->participants()
            ->where('user_id', $userId)
            ->update(['status' => 'left', 'left_at' => now()]);

        // End call if no participants left
        if ($this->activeParticipants()->count() === 0) {
            $this->end();
        }
    }

    public function end(): void
    {
        $this->update([
            'status' => self::STATUS_ENDED,
            'ended_at' => now(),
        ]);

        // Mark all remaining participants as left
        $this->participants()
            ->whereNull('left_at')
            ->update(['status' => 'left', 'left_at' => now()]);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
