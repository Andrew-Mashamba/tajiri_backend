<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use App\Services\ContentEngine\SignalService;
use App\Services\ContentEngine\TrendingService;
use App\Services\ContentEngine\UserSignalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SignalConsumer extends Command
{
    protected $signature = 'signal:consume
                            {--group=signal-consumers : Consumer group name}
                            {--consumer=worker-1 : Consumer name within the group}
                            {--batch=100 : Number of messages to read per iteration}
                            {--timeout=5000 : Block timeout in milliseconds}';

    protected $description = 'Consume engagement events from Redis Stream and update signal counters, trending, and user profiles';

    private bool $running = true;

    /** In-memory cache for document metadata (avoids per-event DB queries). */
    private array $docCache = [];

    public function handle(): int
    {
        $stream = 'engagement:signals';
        $group = $this->option('group');
        $consumer = $this->option('consumer');
        $batch = (int) $this->option('batch');
        $timeout = (int) $this->option('timeout');

        // Create consumer group if it doesn't exist
        try {
            Redis::xGroup('CREATE', $stream, $group, '0', true);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                $this->error("Failed to create consumer group: {$e->getMessage()}");
                return 1;
            }
        }

        $this->info("Signal consumer [{$consumer}] started on group [{$group}]");

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->running = false);
            pcntl_signal(SIGINT, fn() => $this->running = false);
        }

        $processed = 0;
        while ($this->running) {
            try {
                $messages = Redis::xReadGroup(
                    $group,
                    $consumer,
                    [$stream => '>'],
                    $batch,
                    $timeout
                );

                if (empty($messages) || empty($messages[$stream])) {
                    continue;
                }

                foreach ($messages[$stream] as $messageId => $data) {
                    try {
                        $this->processEvent($data);
                        Redis::xAck($stream, $group, [$messageId]);
                        $processed++;
                    } catch (\Throwable $e) {
                        Log::error('Signal consumer event processing failed', [
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                        Redis::xAck($stream, $group, [$messageId]);
                    }
                }

                // Trim stream periodically
                if ($processed % 1000 === 0 && $processed > 0) {
                    Redis::xTrim($stream, 'MAXLEN', '~', 100000);
                }

            } catch (\Throwable $e) {
                Log::error('Signal consumer loop error', ['error' => $e->getMessage()]);
                sleep(1);
            }
        }

        $this->info("Signal consumer [{$consumer}] stopped after processing {$processed} events.");
        return 0;
    }

    private function processEvent(array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $eventType = $data['event_type'] ?? '';
        $postId = (int) ($data['post_id'] ?? 0);
        $creatorId = (int) ($data['creator_id'] ?? 0);
        $durationMs = (int) ($data['duration_ms'] ?? 0);
        $sourceType = $data['source_type'] ?? '';
        $ipAddress = $data['ip'] ?? null;

        if (!$postId || !$eventType) return;

        // Use source_type from stream data (set by UserEventController).
        // Fall back to DB lookup with in-memory cache if missing.
        $doc = null;
        if (empty($sourceType)) {
            $doc = $this->getCachedDoc($postId);
            if (!$doc) return;
            $sourceType = $doc->source_type;
        }

        // Worker 1: Document Score Updater
        SignalService::incrementSignal(
            $sourceType,
            $postId,
            $eventType,
            $durationMs,
            $userId,
            $ipAddress
        );

        // Worker 3: User Profile Updater
        if ($userId > 0) {
            if (!$doc) {
                $doc = $this->getCachedDoc($postId);
            }
            UserSignalService::updateProfile(
                $userId,
                $eventType,
                $creatorId ?: ($doc->creator_id ?? 0),
                $doc->category ?? null,
                $doc->hashtags ?? [],
                $doc->media_types ?? [],
                $durationMs
            );
        }
    }

    private function getCachedDoc(int $sourceId): ?ContentDocument
    {
        if (isset($this->docCache[$sourceId])) {
            return $this->docCache[$sourceId];
        }

        $doc = ContentDocument::where('source_id', $sourceId)
            ->first(['source_type', 'source_id', 'creator_id', 'category', 'hashtags', 'media_types', 'region_name']);

        if ($doc) {
            if (count($this->docCache) >= 2000) {
                array_shift($this->docCache);
            }
            $this->docCache[$sourceId] = $doc;
        }

        return $doc;
    }
}
