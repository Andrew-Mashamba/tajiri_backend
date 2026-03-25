<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'is_admin',
        'last_read_at',
        'unread_count',
        'is_muted',
        'is_pinned',
        'is_favorite',
        'is_archived',
        'folder',
        'is_typing',
        'typing_started_at',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'is_muted' => 'boolean',
        'is_pinned' => 'boolean',
        'is_favorite' => 'boolean',
        'is_archived' => 'boolean',
        'last_read_at' => 'datetime',
        'unread_count' => 'integer',
        'is_typing' => 'boolean',
        'typing_started_at' => 'datetime',
    ];

    /**
     * Get the conversation.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Mark conversation as read.
     */
    public function markAsRead(): void
    {
        $this->update([
            'last_read_at' => now(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Increment unread count.
     */
    public function incrementUnread(): void
    {
        $this->increment('unread_count');
    }

    /**
     * Toggle mute status.
     */
    public function toggleMute(): void
    {
        $this->update(['is_muted' => !$this->is_muted]);
    }

    /**
     * Toggle pin status.
     */
    public function togglePin(): void
    {
        $this->update(['is_pinned' => !$this->is_pinned]);
    }

    /**
     * Toggle favorite status.
     */
    public function toggleFavorite(): void
    {
        $this->update(['is_favorite' => !$this->is_favorite]);
    }

    /**
     * Toggle archive status.
     */
    public function toggleArchive(): void
    {
        $this->update(['is_archived' => !$this->is_archived]);
    }

    /**
     * Set folder for this conversation.
     */
    public function setFolder(?string $folder): void
    {
        $this->update(['folder' => $folder]);
    }

    /**
     * Start typing indicator.
     */
    public function startTyping(): void
    {
        $this->update([
            'is_typing' => true,
            'typing_started_at' => now(),
        ]);
    }

    /**
     * Stop typing indicator.
     */
    public function stopTyping(): void
    {
        $this->update([
            'is_typing' => false,
            'typing_started_at' => null,
        ]);
    }

    /**
     * Check if actively typing (within last 5 seconds).
     */
    public function isActivelyTyping(): bool
    {
        if (!$this->is_typing || !$this->typing_started_at) {
            return false;
        }

        return $this->typing_started_at->diffInSeconds(now()) < 5;
    }
}
