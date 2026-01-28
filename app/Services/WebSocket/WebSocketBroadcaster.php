<?php

namespace App\Services\WebSocket;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Broadcasts messages to plain WebSocket clients via Redis pub/sub.
 *
 * The WebSocket server subscribes to Redis channels and forwards
 * messages to connected clients. This allows REST API endpoints
 * to push real-time updates without direct WebSocket access.
 *
 * Message format: {"event": "event_name", "data": {...}}
 */
class WebSocketBroadcaster
{
    /**
     * Broadcast a message to all viewers of a stream.
     *
     * @param int $streamId
     * @param string $event Event name (e.g., 'new_comment', 'gift_sent')
     * @param array $data Event payload
     */
    public static function broadcast(int $streamId, string $event, array $data): void
    {
        $payload = json_encode([
            'event' => $event,
            'data' => $data,
        ]);

        try {
            Redis::publish("stream_{$streamId}", $payload);
        } catch (\Exception $e) {
            Log::warning("WebSocket broadcast failed for stream {$streamId}: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast viewer count update.
     */
    public static function viewerCountUpdated(int $streamId, int $currentViewers, int $peakViewers): void
    {
        self::broadcast($streamId, 'viewer_count_updated', [
            'current_viewers' => $currentViewers,
            'peak_viewers' => $peakViewers,
        ]);
    }

    /**
     * Broadcast a new comment.
     */
    public static function newComment(int $streamId, array $commentData): void
    {
        self::broadcast($streamId, 'new_comment', $commentData);
    }

    /**
     * Broadcast a gift sent event.
     */
    public static function giftSent(int $streamId, array $giftData): void
    {
        self::broadcast($streamId, 'gift_sent', $giftData);
    }

    /**
     * Broadcast a reaction.
     */
    public static function reaction(int $streamId, int $userId, string $reactionType): void
    {
        self::broadcast($streamId, 'reaction', [
            'user_id' => $userId,
            'reaction_type' => $reactionType,
        ]);
    }

    /**
     * Broadcast a status change.
     */
    public static function statusChanged(int $streamId, string $oldStatus, string $newStatus): void
    {
        self::broadcast($streamId, 'status_changed', [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
    }
}
