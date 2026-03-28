<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use App\Services\ContentEngine\SignalService;
use App\Services\ContentEngine\TypesenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ScoreSyncWorker extends Command
{
    protected $signature = 'signal:sync-scores
                            {--once : Run once then exit (for testing)}';
    protected $description = 'Flush dirty scores from Redis to PostgreSQL and Typesense';

    private bool $running = true;

    public function handle(): int
    {
        $dirtyKey = config('content-engine.score_sync.dirty_set_key', 'scores:dirty');
        $interval = config('content-engine.score_sync.sync_interval_seconds', 30);
        $batchSize = config('content-engine.score_sync.batch_size', 200);

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->running = false);
            pcntl_signal(SIGINT, fn() => $this->running = false);
        }

        $this->info("Score sync worker started (interval: {$interval}s, batch: {$batchSize})");

        while ($this->running) {
            try {
                $synced = $this->syncBatch($dirtyKey, $batchSize);

                if ($synced > 0) {
                    $this->line("  Synced {$synced} documents");
                }
            } catch (\Throwable $e) {
                Log::error('Score sync worker error', ['error' => $e->getMessage()]);
            }

            if ($this->option('once')) break;

            sleep($interval);
        }

        $this->info('Score sync worker stopped.');
        return 0;
    }

    private function syncBatch(string $dirtyKey, int $batchSize): int
    {
        // Atomically pop dirty members using SPOP with count (Redis 3.2+)
        $members = Redis::command('spop', [$dirtyKey, $batchSize]);

        if (empty($members)) return 0;

        $synced = 0;

        foreach ($members as $member) {
            $parts = explode(':', $member, 2);
            if (count($parts) !== 2) continue;
            [$sourceType, $sourceId] = $parts;
            $sourceId = (int) $sourceId;

            try {
                // Read scores from Redis
                $docKey = SignalService::docKey($sourceType, $sourceId);
                $engagementScore = (float) (Redis::hGet($docKey, 'engagement_score') ?? 0);
                $trendingScore = (float) (Redis::hGet($docKey, 'trending_score') ?? 0);

                // Update PostgreSQL
                $doc = ContentDocument::where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->first();

                if (!$doc) continue;

                $doc->engagement_score = $engagementScore;
                $doc->trending_score = $trendingScore;

                // Use shared composite formula (DRY)
                $doc->recomputeCompositeAndTier(save: true);

                // Sync to Typesense
                try {
                    TypesenseService::upsert($doc);
                } catch (\Throwable $e) {
                    Log::warning('Score sync: Typesense upsert failed', [
                        'doc' => "{$sourceType}:{$sourceId}",
                        'error' => $e->getMessage(),
                    ]);
                }

                $synced++;
            } catch (\Throwable $e) {
                Log::error('Score sync: document sync failed', [
                    'doc' => "{$sourceType}:{$sourceId}",
                    'error' => $e->getMessage(),
                ]);
                // Re-add to dirty set for retry
                Redis::sAdd($dirtyKey, $member);
            }
        }

        return $synced;
    }
}
