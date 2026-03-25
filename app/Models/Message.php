<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'content',
        'message_type',
        'media_path',
        'media_type',
        'reply_to_id',
        'forward_message_id',
        'call_session_id',
        'is_read',
        'read_at',
        'edited_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    protected $appends = ['reactions_grouped'];

    /**
     * Message types
     */
    const TYPE_TEXT = 'text';
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_DOCUMENT = 'document';
    const TYPE_LOCATION = 'location';
    const TYPE_CONTACT = 'contact';
    const TYPE_MISSED_CALL_VOICE = 'missed_call_voice';

    /**
     * Get the conversation.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the sender.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'sender_id');
    }

    /**
     * Get the message this is replying to.
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /**
     * Get the original message when this is a forwarded message.
     */
    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'forward_message_id');
    }

    /**
     * Get the associated call session (for missed_call_voice messages).
     */
    public function callSession(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_session_id');
    }

    /**
     * Get reactions on this message.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Get reactions grouped by emoji for API response.
     */
    public function getReactionsGroupedAttribute(): array
    {
        if (!$this->relationLoaded('reactions')) {
            return [];
        }

        return $this->reactions
            ->groupBy('emoji')
            ->map(function ($reactions, $emoji) {
                return [
                    'emoji' => $emoji,
                    'user_ids' => $reactions->pluck('user_id')->values()->toArray(),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Mark message as read.
     */
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Check if message has media.
     */
    public function hasMedia(): bool
    {
        return $this->media_path !== null;
    }

    /**
     * Get media URL.
     */
    public function getMediaUrlAttribute(): ?string
    {
        return $this->media_path ? asset('storage/' . $this->media_path) : null;
    }

    /**
     * Check if message is from a specific user.
     */
    public function isFrom(int $userId): bool
    {
        return $this->sender_id === $userId;
    }
}
