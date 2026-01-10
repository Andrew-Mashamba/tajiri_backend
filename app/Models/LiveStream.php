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
        'viewers_count',
        'peak_viewers',
        'total_viewers',
        'likes_count',
        'comments_count',
        'gifts_count',
        'shares_count',
        'gifts_value',
        'scheduled_at',
        'started_at',
        'ended_at',
        'duration',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_recorded' => 'boolean',
        'allow_comments' => 'boolean',
        'allow_gifts' => 'boolean',
        'gifts_value' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($stream) {
            if (!$stream->stream_key) {
                $stream->stream_key = Str::uuid()->toString();
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

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function start(): void
    {
        $this->update([
            'status' => 'live',
            'started_at' => now(),
        ]);
    }

    public function end(): void
    {
        $duration = $this->started_at ? now()->diffInSeconds($this->started_at) : 0;
        $this->update([
            'status' => 'ended',
            'ended_at' => now(),
            'duration' => $duration,
        ]);
    }

    public function updateViewerCount(): void
    {
        $count = $this->viewers()->whereNull('left_at')->count();
        $this->update([
            'viewers_count' => $count,
            'peak_viewers' => max($this->peak_viewers, $count),
        ]);
    }

    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeEnded($query)
    {
        return $query->where('status', 'ended');
    }
}
