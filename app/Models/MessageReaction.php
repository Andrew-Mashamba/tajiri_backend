<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'user_id',
        'emoji',
    ];

    /**
     * Get the message this reaction belongs to.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the user who reacted.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Get grouped reactions for a message.
     * Returns: [{ "emoji": "👍", "user_ids": [1, 2] }, ...]
     */
    public static function getGroupedForMessage(int $messageId): array
    {
        return self::where('message_id', $messageId)
            ->get()
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
     * Toggle a reaction: add if not exists, remove if exists.
     * Returns true if added, false if removed.
     */
    public static function toggle(int $messageId, int $userId, string $emoji): bool
    {
        $existing = self::where('message_id', $messageId)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        self::create([
            'message_id' => $messageId,
            'user_id' => $userId,
            'emoji' => $emoji,
        ]);

        return true;
    }
}
