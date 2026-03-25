<?php

namespace App\Services\WebSocket;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Broadcasts messages to WebSocket clients via Redis pub/sub.
 *
 * The WebSocket server subscribes to Redis channels and forwards
 * messages to connected clients. This allows REST API endpoints
 * to push real-time updates without direct WebSocket access.
 *
 * Supports two types of channels:
 * - Stream-specific: stream_{id} - for viewers of a specific stream
 * - Global: live-streams - for ALL users on the Live tab
 *
 * Message format: {"event": "event_name", "data": {...}}
 */
class WebSocketBroadcaster
{
    /**
     * Broadcast a message to all viewers of a specific stream.
     *
     * @param int $streamId
     * @param string $event Event name (e.g., 'new_comment', 'gift_sent')
     * @param array $data Event payload
     */
    public static function broadcast(int $streamId, string $event, array $data): void
    {
        $payload = json_encode([
            'event' => $event,
            'data' => array_merge($data, [
                'stream_id' => $streamId,
                'timestamp' => now()->toIso8601String(),
            ]),
        ]);

        try {
            Redis::publish("stream_{$streamId}", $payload);
        } catch (\Exception $e) {
            Log::warning("WebSocket broadcast failed for stream {$streamId}: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast a message to ALL users on the Live tab (global channel).
     *
     * @param string $event Event name
     * @param array $data Event payload
     */
    public static function broadcastGlobal(string $event, array $data): void
    {
        $payload = json_encode([
            'event' => $event,
            'data' => array_merge($data, [
                'timestamp' => now()->toIso8601String(),
            ]),
        ]);

        try {
            Redis::publish('live-streams', $payload);
        } catch (\Exception $e) {
            Log::warning("WebSocket global broadcast failed: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast viewer count update to both stream-specific and global channels.
     */
    public static function viewerCountUpdated(int $streamId, int $currentViewers, int $peakViewers, int $uniqueViewers = 0): void
    {
        $data = [
            'stream_id' => $streamId,
            'viewers_count' => $currentViewers,
            'peak_viewers' => $peakViewers,
            'unique_viewers' => $uniqueViewers,
        ];

        // Broadcast to stream-specific channel (for viewers watching this stream)
        self::broadcast($streamId, 'viewer_count_updated', $data);

        // Broadcast to global channel (for Live tab grid)
        self::broadcastGlobal('viewer_count_updated', $data);
    }

    /**
     * Broadcast stream status change to both channels.
     */
    public static function statusChanged(int $streamId, string $oldStatus, string $newStatus, ?array $streamData = null): void
    {
        $data = [
            'stream_id' => $streamId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];

        if ($streamData) {
            $data['stream'] = $streamData;
        }

        // Broadcast to stream-specific channel
        self::broadcast($streamId, 'status_changed', $data);

        // Broadcast to global channel (for Live tab)
        self::broadcastGlobal('stream_status_changed', $data);
    }

    /**
     * Broadcast when a new stream goes live (global only).
     */
    public static function newStreamStarted(array $streamData): void
    {
        self::broadcastGlobal('new_stream_started', [
            'stream' => $streamData,
        ]);
    }

    /**
     * Broadcast a new comment (stream-specific only).
     */
    public static function newComment(int $streamId, array $commentData): void
    {
        self::broadcast($streamId, 'new_comment', $commentData);
    }

    /**
     * Broadcast a gift sent event (stream-specific only).
     */
    public static function giftSent(int $streamId, array $giftData): void
    {
        self::broadcast($streamId, 'gift_sent', $giftData);
    }

    /**
     * Broadcast a reaction (stream-specific only).
     */
    public static function reaction(int $streamId, int $userId, string $reactionType, array $counts = []): void
    {
        self::broadcast($streamId, 'reaction', [
            'user_id' => $userId,
            'reaction_type' => $reactionType,
            'counts' => $counts,
        ]);
    }

    /**
     * Broadcast poll update (stream-specific only).
     */
    public static function pollUpdated(int $streamId, array $pollData): void
    {
        self::broadcast($streamId, 'poll_updated', $pollData);
    }

    /**
     * Broadcast Q&A update (stream-specific only).
     */
    public static function questionUpdated(int $streamId, array $questionData): void
    {
        self::broadcast($streamId, 'question_updated', $questionData);
    }

    /**
     * Broadcast super chat message (stream-specific only).
     */
    public static function superChat(int $streamId, array $superChatData): void
    {
        self::broadcast($streamId, 'super_chat', $superChatData);
    }

    /**
     * Broadcast co-host joined (stream-specific only).
     */
    public static function cohostJoined(int $streamId, array $cohostData): void
    {
        self::broadcast($streamId, 'cohost_joined', $cohostData);
    }

    /**
     * Broadcast battle update (stream-specific only).
     */
    public static function battleUpdated(int $streamId, array $battleData): void
    {
        self::broadcast($streamId, 'battle_updated', $battleData);
    }
}
