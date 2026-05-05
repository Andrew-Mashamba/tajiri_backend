<?php

namespace App\Console\Commands;

use App\Services\ContentEngine\DuplicateDetectionService;
use Illuminate\Console\Command;

class DetectDuplicates extends Command
{
    protected $signature = 'content:detect-duplicates
                            {--hours=24 : Scan content from the last N hours}
                            {--threshold=0.95 : Cosine similarity threshold}';
    protected $description = 'Scan recent content for near-duplicates using pgvector cosine similarity';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $threshold = (float) $this->option('threshold');
        $this->info("Scanning content from the last {$hours} hours for near-duplicates (threshold: {$threshold})...");

        $stats = DuplicateDetectionService::batchScan($hours, threshold: $threshold);

        $this->info("Scanned: {$stats['scanned']}");
        $this->info("Duplicates found: {$stats['duplicates_found']}");
        $this->info("Penalized: {$stats['penalized']}");

        return 0;
    }
}
