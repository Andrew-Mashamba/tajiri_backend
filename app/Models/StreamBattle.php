<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamBattle extends Model
{
    protected $fillable = [
        'stream_id_1',
        'stream_id_2',
        'status',
        'score_1',
        'score_2',
        'winner_stream_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_ENDED = 'ended';
    const STATUS_CANCELLED = 'cancelled';

    public function stream1(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id_1');
    }

    public function stream2(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'stream_id_2');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class, 'winner_stream_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getOpponentStream(int $streamId): ?LiveStream
    {
        if ($this->stream_id_1 === $streamId) {
            return $this->stream2;
        }
        if ($this->stream_id_2 === $streamId) {
            return $this->stream1;
        }
        return null;
    }

    public function getScoreFor(int $streamId): int
    {
        if ($this->stream_id_1 === $streamId) {
            return $this->score_1;
        }
        if ($this->stream_id_2 === $streamId) {
            return $this->score_2;
        }
        return 0;
    }

    public function addScore(int $streamId, int $points): void
    {
        if ($this->stream_id_1 === $streamId) {
            $this->increment('score_1', $points);
        } elseif ($this->stream_id_2 === $streamId) {
            $this->increment('score_2', $points);
        }
    }

    public function determineWinner(): ?int
    {
        if ($this->score_1 > $this->score_2) {
            return $this->stream_id_1;
        }
        if ($this->score_2 > $this->score_1) {
            return $this->stream_id_2;
        }
        return null; // tie
    }
}
