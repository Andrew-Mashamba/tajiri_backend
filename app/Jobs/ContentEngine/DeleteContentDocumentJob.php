<?php

namespace App\Jobs\ContentEngine;

use App\Models\ContentDocument;
use App\Services\ContentEngine\TypesenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteContentDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $sourceType,
        public int $sourceId,
    ) {
        $this->onQueue('content-ingestion');
    }

    public function handle(): void
    {
        $doc = ContentDocument::where('source_type', $this->sourceType)
            ->where('source_id', $this->sourceId)
            ->first();

        if (!$doc) {
            return;
        }

        $docId = $doc->id;
        $doc->delete();
        TypesenseService::delete($docId);

        Log::info("DeleteContentDocumentJob: deleted", [
            'source' => "{$this->sourceType}:{$this->sourceId}",
            'doc_id' => $docId,
        ]);
    }
}
