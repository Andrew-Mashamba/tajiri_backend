<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'avatar_path',
        'created_by',
        'last_message_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * Conversation types
     */
    const TYPE_PRIVATE = 'private';
    const TYPE_GROUP = 'group';

    /**
     * Get the creator of the conversation.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'created_by');
    }

    /**
     * Get all participants.
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(UserProfile::class, 'conversation_participants', 'conversation_id', 'user_id')
            ->withPivot(['is_admin', 'last_read_at', 'unread_count', 'is_muted'])
            ->withTimestamps();
    }

    /**
     * Get participant records.
     */
    public function participantRecords(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * Get messages in this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the last message.
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
     * Check if conversation is a private chat.
     */
    public function isPrivate(): bool
    {
        return $this->type === self::TYPE_PRIVATE;
    }

    /**
     * Check if conversation is a group chat.
     */
    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /**
     * Check if user is a participant.
     */
    public function hasParticipant(int $userId): bool
    {
        return $this->participants()->where('user_id', $userId)->exists();
    }

    /**
     * Get the other participant in a private conversation.
     */
    public function getOtherParticipant(int $userId): ?UserProfile
    {
        if (!$this->isPrivate()) {
            return null;
        }

        return $this->participants()->where('user_id', '!=', $userId)->first();
    }

    /**
     * Update last message info.
     */
    public function updateLastMessage(Message $message): void
    {
        $this->update([
            'last_message_id' => $message->id,
            'last_message_at' => $message->created_at,
        ]);
    }

    /**
     * Get or create private conversation between two users.
     */
    public static function getOrCreatePrivate(int $userId1, int $userId2): self
    {
        // Look for existing private conversation
        $conversation = self::where('type', self::TYPE_PRIVATE)
            ->whereHas('participants', function ($query) use ($userId1) {
                $query->where('user_id', $userId1);
            })
            ->whereHas('participants', function ($query) use ($userId2) {
                $query->where('user_id', $userId2);
            })
            ->first();

        if ($conversation) {
            return $conversation;
        }

        // Create new private conversation
        $conversation = self::create([
            'type' => self::TYPE_PRIVATE,
            'created_by' => $userId1,
        ]);

        // Add participants
        $conversation->participants()->attach([
            $userId1 => ['is_admin' => false],
            $userId2 => ['is_admin' => false],
        ]);

        return $conversation;
    }
}
