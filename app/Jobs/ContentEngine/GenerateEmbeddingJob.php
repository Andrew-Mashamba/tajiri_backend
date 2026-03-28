<?php

namespace App\Jobs\ContentEngine;

use App\Models\ContentDocument;
use App\Services\ContentEngine\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(public int $documentId)
    {
        $this->onQueue('content-embedding');
    }

    public function handle(): void
    {
        $doc = ContentDocument::find($this->documentId);

        if (!$doc) {
            return;
        }

        $text = trim(($doc->title ?? '') . ' ' . ($doc->body ?? ''));

        if (empty($text)) {
            Log::info("GenerateEmbeddingJob: empty text, skipping", ['id' => $this->documentId]);
            return;
        }

        $words = preg_split('/\s+/', $text);
        if (count($words) > 500) {
            $text = implode(' ', array_slice($words, 0, 500));
        }

        $embedding = EmbeddingService::embed($text);

        if ($embedding === null) {
            Log::error("GenerateEmbeddingJob: embedding failed", ['id' => $this->documentId]);
            throw new \RuntimeException("Embedding generation failed for document {$this->documentId}");
        }

        $vectorStr = '[' . implode(',', $embedding) . ']';
        DB::statement(
            'UPDATE content_documents SET embedding = ?::vector WHERE id = ?',
            [$vectorStr, $this->documentId]
        );
    }
}
