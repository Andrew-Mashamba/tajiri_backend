<?php

namespace App\Jobs\ContentEngine;

use App\Models\ContentDocument;
use App\Services\ContentEngine\ContentDocumentFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContentIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $modelClass,
    ) {
        $this->onQueue('content-ingestion');
    }

    public function handle(): void
    {
        $model = $this->modelClass::find($this->sourceId);

        if (!$model) {
            Log::warning("ContentIngestionJob: source not found", [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
            ]);
            return;
        }

        try {
            $attributes = ContentDocumentFactory::fromModel($model);

            $halfLife = config("content-engine.scoring.freshness_half_lives.{$this->sourceType}", 24);
            $hoursSince = now()->diffInSeconds($attributes['published_at']) / 3600;
            $attributes['freshness_score'] = 100 * exp(-log(2) / $halfLife * $hoursSince);

            $doc = ContentDocument::updateOrCreate(
                ['source_type' => $this->sourceType, 'source_id' => $this->sourceId],
                array_merge($attributes, ['indexed_at' => now()])
            );

            SyncToTypesenseJob::dispatch($doc->id)->onQueue('typesense-sync');
            GenerateEmbeddingJob::dispatch($doc->id)->onQueue('content-embedding');
            ClaudeScoreContentJob::dispatch($doc->id)->onQueue('content-scoring');

            Log::info("ContentIngestionJob: ingested", [
                'doc_id' => $doc->id,
                'source' => "{$this->sourceType}:{$this->sourceId}",
            ]);
        } catch (\Throwable $e) {
            Log::error("ContentIngestionJob: failed", [
                'source' => "{$this->sourceType}:{$this->sourceId}",
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
