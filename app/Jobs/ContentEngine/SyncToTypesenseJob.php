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

class SyncToTypesenseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(public int $documentId)
    {
        $this->onQueue('typesense-sync');
    }

    public function handle(): void
    {
        $doc = ContentDocument::find($this->documentId);

        if (!$doc) {
            Log::warning("SyncToTypesenseJob: document not found", ['id' => $this->documentId]);
            return;
        }

        $success = TypesenseService::upsert($doc);

        if (!$success) {
            Log::error("SyncToTypesenseJob: upsert failed", ['id' => $this->documentId]);
            throw new \RuntimeException("Typesense upsert failed for document {$this->documentId}");
        }
    }
}
