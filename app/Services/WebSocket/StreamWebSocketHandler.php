<?php

namespace App\Services\WebSocket;

use App\Models\LiveStream;
use App\Models\StreamViewer;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamWebSocketHandler
{
    /**
     * Handle a new WebSocket connection.
     *
     * Called when a client connects to: wss://zima-uat.site/streams/{stream_id}?user_id={user_id}
     *
     * @param mixed $connection The WebSocket connection instance
     * @param int $streamId
     * @param int|null $userId
     */
    public function onConnection($connection, int $streamId, ?int $userId): void
    {
        // Validate stream exists and is active
        $stream = LiveStream::find($streamId);
        if (!$stream || !in_array($stream->status, ['pre_live', 'live'])) {
            $connection->send(json_encode([
                'event' => 'error',
                'data' => ['message' => 'Stream not available'],
            ]));
            $connection->close();
            return;
        }

        // Validate user if provided
        if ($userId) {
            $user = UserProfile::find($userId);
            if (!$user) {
                $connection->send(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'Invalid user'],
                ]));
                $connection->close();
                return;
            }
        }

        // Store connection metadata
        $connection->streamId = $streamId;
        $connection->userId = $userId;

        // Join the stream room
        $this->joinRoom($connection, "stream_{$streamId}");

        // Track viewer
        if ($userId) {
            $existingViewer = StreamViewer::where('stream_id', $streamId)
                ->where('user_id', $userId)
                ->where('is_currently_watching', true)
                ->first();

            if (!$existingViewer) {
                StreamViewer::create([
                    'stream_id' => $streamId,
                    'user_id' => $userId,
                    'joined_at' => now(),
                    'is_currently_watching' => true,
                ]);

                $stream->increment('total_viewers');
            }
        }

        // Update viewer count
        $this->incrementViewerCount($streamId);
        $currentViewers = $this->getViewerCount($streamId);
        $peakViewers = $this->updatePeakViewers($streamId, $currentViewers);

        // Broadcast viewer count update to all connected clients
        $this->broadcast("stream_{$streamId}", [
            'event' => 'viewer_count_updated',
            'data' => [
                'current_viewers' => $currentViewers,
                'peak_viewers' => $peakViewers,
            ],
        ]);
    }

    /**
     * Handle incoming WebSocket messages.
     *
     * @param mixed $connection
     * @param string $message Raw JSON message from client
     */
    public function onMessage($connection, string $message): void
    {
        $data = json_decode($message, true);

        if (!$data || !isset($data['event'])) {
            return;
        }

        switch ($data['event']) {
            case 'ping':
                $connection->send(json_encode([
                    'event' => 'pong',
                    'data' => [
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]));
                break;

            case 'reaction':
                $this->handleReaction($connection, $data['data'] ?? []);
                break;
        }
    }

    /**
     * Handle WebSocket disconnection.
     *
     * @param mixed $connection
     * @param int $streamId
     * @param int|null $userId
     */
    public function onDisconnect($connection, int $streamId, ?int $userId): void
    {
        // Decrement viewer count
        $this->decrementViewerCount($streamId);
        $currentViewers = $this->getViewerCount($streamId);

        // Broadcast updated viewer count
        $this->broadcast("stream_{$streamId}", [
            'event' => 'viewer_count_updated',
            'data' => [
                'current_viewers' => $currentViewers,
            ],
        ]);

        // Update viewer record
        if ($userId) {
            $viewer = StreamViewer::where('stream_id', $streamId)
                ->where('user_id', $userId)
                ->where('is_currently_watching', true)
                ->first();

            if ($viewer) {
                $watchDuration = now()->diffInSeconds($viewer->joined_at);
                $viewer->update([
                    'left_at' => now(),
                    'watch_duration' => $watchDuration,
                    'is_currently_watching' => false,
                ]);
            }
        }
    }

    /**
     * Handle a reaction event from a client.
     */
    private function handleReaction($connection, array $data): void
    {
        $streamId = $connection->streamId ?? null;
        $userId = $connection->userId ?? null;

        if (!$streamId || !isset($data['reaction_type'])) {
            return;
        }

        $reactionType = $data['reaction_type'];
        $allowedReactions = ['heart', 'fire', 'love', 'wow', 'clap', 'laugh'];

        if (!in_array($reactionType, $allowedReactions)) {
            return;
        }

        // Update reaction counts on the stream
        $stream = LiveStream::find($streamId);
        if ($stream) {
            $counts = $stream->reaction_counts ?? [];
            $counts[$reactionType] = ($counts[$reactionType] ?? 0) + 1;
            $stream->update(['reaction_counts' => $counts]);
        }

        // Broadcast to all viewers
        $this->broadcast("stream_{$streamId}", [
            'event' => 'reaction',
            'data' => [
                'user_id' => $userId,
                'reaction_type' => $reactionType,
            ],
        ]);
    }

    // --- Redis-backed viewer count helpers ---

    private function incrementViewerCount(int $streamId): void
    {
        try {
            Redis::incr("stream:{$streamId}:viewers");
        } catch (\Exception $e) {
            Log::warning("Redis unavailable for viewer count: {$e->getMessage()}");
        }
    }

    private function decrementViewerCount(int $streamId): void
    {
        try {
            $count = Redis::decr("stream:{$streamId}:viewers");
            if ($count < 0) {
                Redis::set("stream:{$streamId}:viewers", 0);
            }
        } catch (\Exception $e) {
            Log::warning("Redis unavailable for viewer count: {$e->getMessage()}");
        }
    }

    private function getViewerCount(int $streamId): int
    {
        try {
            return max(0, (int) Redis::get("stream:{$streamId}:viewers"));
        } catch (\Exception $e) {
            // Fallback to DB count
            return StreamViewer::where('stream_id', $streamId)
                ->where('is_currently_watching', true)
                ->count();
        }
    }

    private function updatePeakViewers(int $streamId, int $currentViewers): int
    {
        try {
            $peak = (int) Redis::get("stream:{$streamId}:peak_viewers");
            if ($currentViewers > $peak) {
                Redis::set("stream:{$streamId}:peak_viewers", $currentViewers);
                LiveStream::where('id', $streamId)->update(['peak_viewers' => $currentViewers]);
                return $currentViewers;
            }
            return $peak;
        } catch (\Exception $e) {
            return $currentViewers;
        }
    }

    // --- Room management (to be wired to your WS server) ---

    /**
     * Join a room. Implementation depends on your WebSocket server (Ratchet, Swoole, etc.)
     */
    protected function joinRoom($connection, string $room): void
    {
        // Override in your WebSocket server adapter
        // e.g., $this->rooms[$room][] = $connection;
    }

    /**
     * Broadcast a message to all connections in a room.
     * Implementation depends on your WebSocket server.
     */
    protected function broadcast(string $room, array $payload): void
    {
        // Override in your WebSocket server adapter
        // e.g., foreach ($this->rooms[$room] as $conn) { $conn->send(json_encode($payload)); }
    }
}
