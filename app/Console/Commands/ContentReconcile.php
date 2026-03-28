<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ContentReconcile extends Command
{
    protected $signature = 'content:reconcile';
    protected $description = 'Check Content Engine data integrity — index coverage, sync completeness, orphans';

    public function handle(): int
    {
        $this->info('Content Engine Reconciliation');
        $this->info(str_repeat('=', 50));

        $issues = 0;

        // 1. Index coverage — every published post should have a content_document
        $postCount = DB::table('posts')
            ->where(function ($q) {
                $q->where('is_draft', false)->orWhereNull('is_draft');
            })
            ->count();
        $indexedPosts = ContentDocument::where('source_type', 'post')->count();
        $coverage = $postCount > 0 ? round($indexedPosts / $postCount * 100, 1) : 100;
        $this->line("  Posts: {$indexedPosts}/{$postCount} indexed ({$coverage}%)");
        if ($coverage < 95) {
            $this->warn("    LOW COVERAGE — run: php artisan content:reindex --type=posts");
            $issues++;
        }

        // 2. Total documents indexed
        $totalDocs = ContentDocument::count();
        $this->line("  Total documents: {$totalDocs}");

        // 3. Typesense sync check
        $config = config('content-engine.typesense');
        try {
            $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/collections/{$config['collection']}";
            $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => $config['api_key']])->timeout(5)->get($url);
            $typesenseCount = $response->json()['num_documents'] ?? 0;
            $drift = abs($totalDocs - $typesenseCount);
            $this->line("  Typesense documents: {$typesenseCount} (drift: {$drift})");
            if ($drift > max(10, $totalDocs * 0.05)) {
                $this->warn("    SYNC DRIFT — Typesense is out of sync");
                $issues++;
            }
        } catch (\Throwable $e) {
            $this->error("  Typesense: UNREACHABLE — {$e->getMessage()}");
            $issues++;
        }

        // 4. Embedding coverage
        $withEmbedding = DB::table('content_documents')
            ->whereNotNull('embedding')
            ->count();
        $embeddingPct = $totalDocs > 0 ? round($withEmbedding / $totalDocs * 100, 1) : 100;
        $this->line("  Embeddings: {$withEmbedding}/{$totalDocs} ({$embeddingPct}%)");
        if ($embeddingPct < 80 && $totalDocs > 0) {
            $this->warn("    LOW EMBEDDING COVERAGE");
            $issues++;
        }

        // 5. Score freshness
        $staleCount = ContentDocument::where('scores_updated_at', '<', now()->subHours(2))
            ->orWhereNull('scores_updated_at')
            ->count();
        $stalePct = $totalDocs > 0 ? round($staleCount / $totalDocs * 100, 1) : 0;
        $this->line("  Stale scores (>2h): {$staleCount} ({$stalePct}%)");

        // 6. Tier distribution
        $tiers = ContentDocument::selectRaw('content_tier, COUNT(*) as cnt')
            ->groupBy('content_tier')
            ->pluck('cnt', 'content_tier')
            ->toArray();
        $this->line("  Tier distribution: " . json_encode($tiers));

        $this->newLine();
        if ($issues === 0) {
            $this->info("No issues found.");
        } else {
            $this->warn("{$issues} issue(s) found. See warnings above.");
        }

        return $issues > 0 ? 1 : 0;
    }
}
