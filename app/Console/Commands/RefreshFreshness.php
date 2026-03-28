<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class RefreshFreshness extends Command
{
    protected $signature = 'content:refresh-freshness
                            {--batch-size=500 : Records per batch for composite recompute}';
    protected $description = 'Bulk-update freshness_score and recompute composite_score for all content documents';

    public function handle(): int
    {
        $halfLives = config('content-engine.scoring.freshness_half_lives', []);
        $batchSize = (int) $this->option('batch-size');
        $totalUpdated = 0;
        $tierChanges = 0;

        foreach ($halfLives as $sourceType => $halfLife) {
            // Bulk SQL update: only update rows where change > 0.5 points
            $affected = DB::update("
                UPDATE content_documents
                SET freshness_score = ROUND((100 * EXP(-LN(2) / ? * EXTRACT(EPOCH FROM (NOW() - published_at)) / 3600))::numeric, 2)
                WHERE source_type = ?
                  AND published_at IS NOT NULL
                  AND ABS(freshness_score - (100 * EXP(-LN(2) / ? * EXTRACT(EPOCH FROM (NOW() - published_at)) / 3600))) > 0.5
            ", [$halfLife, $sourceType, $halfLife]);

            if ($affected === 0) continue;

            $this->line("  {$sourceType}: {$affected} freshness scores updated");
            $totalUpdated += $affected;

            // Recompute composite and tier for affected rows
            $dirtyKey = config('content-engine.score_sync.dirty_set_key', 'scores:dirty');

            ContentDocument::where('source_type', $sourceType)
                ->whereNotNull('published_at')
                ->orderBy('id')
                ->chunk($batchSize, function ($docs) use (&$tierChanges, $dirtyKey) {
                    foreach ($docs as $doc) {
                        $oldTier = $doc->content_tier;
                        $doc->recomputeCompositeAndTier(save: true);

                        if ($oldTier !== $doc->content_tier) {
                            $tierChanges++;
                        }

                        // Add to dirty set so ScoreSyncWorker pushes to Typesense
                        Redis::sAdd($dirtyKey, "{$doc->source_type}:{$doc->source_id}");
                    }
                });
        }

        $this->info("Freshness refresh: {$totalUpdated} updated, {$tierChanges} tier changes");
        return 0;
    }
}
