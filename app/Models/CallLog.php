<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'call_id',
        'group_call_id',
        'other_user_id',
        'type',
        'direction',
        'status',
        'duration',
        'call_time',
    ];

    protected $casts = [
        'duration' => 'integer',
        'call_time' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function otherUser(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'other_user_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function groupCall(): BelongsTo
    {
        return $this->belongsTo(GroupCall::class);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration) return '0:00';

        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
