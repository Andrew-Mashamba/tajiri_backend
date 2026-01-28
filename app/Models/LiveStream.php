<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class LiveStream extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_PRE_LIVE = 'pre_live';
    const STATUS_LIVE = 'live';
    const STATUS_ENDING = 'ending';
    const STATUS_ENDED = 'ended';
    const STATUS_CANCELLED = 'cancelled';

    const VALID_TRANSITIONS = [
        'scheduled' => ['pre_live', 'cancelled'],
        'pre_live' => ['live', 'cancelled'],
        'live' => ['ending', 'cancelled'],
        'ending' => ['ended'],
        'ended' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'stream_key',
        'user_id',
        'title',
        'description',
        'thumbnail_path',
        'category',
        'tags',
        'status',
        'privacy',
        'stream_url',
        'playback_url',
        'recording_path',
        'is_recorded',
        'allow_comments',
        'allow_gifts',
        'allow_co_hosts',
        'viewers_count',
        'peak_viewers',
        'total_viewers',
        'unique_viewers',
        'likes_count',
        'comments_count',
        'gifts_count',
        'shares_count',
        'gifts_value',
        'reaction_counts',
        'scheduled_at',
        'pre_live_started_at',
        'started_at',
        'ended_at',
        'duration',
        // Health monitoring
        'beauty_filter_level',
        'network_quality',
        'average_bitrate',
        'average_fps',
        'total_dropped_frames',
        'average_latency',
    ];

    protected $casts = [
        'tags' => 'array',
        'reaction_counts' => 'array',
        'is_recorded' => 'boolean',
        'allow_comments' => 'boolean',
        'allow_gifts' => 'boolean',
        'allow_co_hosts' => 'boolean',
        'gifts_value' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'pre_live_started_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($stream) {
            if (!$stream->stream_key) {
                $stream->stream_key = Str::random(32);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function viewers(): HasMany
    {
        return $this->hasMany(StreamViewer::class, 'stream_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(StreamComment::class, 'stream_id');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'stream_likes', 'stream_id', 'user_id')->withTimestamps();
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(StreamGift::class, 'stream_id');
    }

    public function cohosts(): HasMany
    {
        return $this->hasMany(StreamCohost::class, 'stream_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(StreamNotification::class, 'stream_id');
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(StreamAnalytics::class, 'stream_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StreamReaction::class, 'stream_id');
    }

    public function polls(): HasMany
    {
        return $this->hasMany(StreamPoll::class, 'stream_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(StreamQuestion::class, 'stream_id');
    }

    public function superChats(): HasMany
    {
        return $this->hasMany(StreamSuperChat::class, 'stream_id');
    }

    public function battlesAsStream1(): HasMany
    {
        return $this->hasMany(StreamBattle::class, 'stream_id_1');
    }

    public function battlesAsStream2(): HasMany
    {
        return $this->hasMany(StreamBattle::class, 'stream_id_2');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? []);
    }

    public function transitionTo(string $newStatus): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $updates = ['status' => $newStatus];

        switch ($newStatus) {
            case self::STATUS_PRE_LIVE:
                $updates['pre_live_started_at'] = now();
                break;
            case self::STATUS_LIVE:
                $updates['started_at'] = now();
                break;
            case self::STATUS_ENDING:
                // Duration will be finalized when transitioning to ended
                break;
            case self::STATUS_ENDED:
                $updates['ended_at'] = now();
                $updates['duration'] = $this->started_at
                    ? now()->diffInSeconds($this->started_at)
                    : 0;
                break;
            case self::STATUS_CANCELLED:
                $updates['ended_at'] = now();
                break;
        }

        return $this->update($updates);
    }

    public function start(): void
    {
        $this->transitionTo(self::STATUS_LIVE);
    }

    public function end(): void
    {
        $this->transitionTo(self::STATUS_ENDING);
    }

    public function updateViewerCount(): void
    {
        $count = $this->viewers()->where('is_currently_watching', true)->count();
        $this->update([
            'viewers_count' => $count,
            'peak_viewers' => max($this->peak_viewers, $count),
        ]);
    }

    public function scopeLive($query)
    {
        return $query->where('status', self::STATUS_LIVE);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopePreLive($query)
    {
        return $query->where('status', self::STATUS_PRE_LIVE);
    }

    public function scopeEnding($query)
    {
        return $query->where('status', self::STATUS_ENDING);
    }

    public function scopeEnded($query)
    {
        return $query->where('status', self::STATUS_ENDED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PRE_LIVE, self::STATUS_LIVE]);
    }
}
