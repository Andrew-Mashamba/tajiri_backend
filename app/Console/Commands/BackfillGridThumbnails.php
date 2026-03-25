<?php

namespace App\Console\Commands;

use App\Services\ImageProcessingService;
use Illuminate\Console\Command;

class BackfillGridThumbnails extends Command
{
    protected $signature = 'posts:backfill-grid-thumbnails {--limit=100 : Number of items to process per batch}';
    protected $description = 'Generate grid thumbnails and extract dominant colors for existing post media';

    public function handle(ImageProcessingService $service): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Processing up to {$limit} media items...");

        $processed = $service->backfillGridThumbnails($limit);

        $this->info("Done. Processed {$processed} media items.");
        return Command::SUCCESS;
    }
}
