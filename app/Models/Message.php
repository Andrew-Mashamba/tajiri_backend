<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

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
