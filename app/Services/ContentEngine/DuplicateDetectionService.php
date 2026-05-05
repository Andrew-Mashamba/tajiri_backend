<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DuplicateDetectionService
{
    /**
     * Find near-duplicates for a document using pgvector cosine similarity.
     * Returns array of [id, source_type, source_id, creator_id, similarity].
     */
    public static function findDuplicates(int $docId, float $threshold = 0.95, int $limit = 5): array
    {
        $doc = ContentDocument::find($docId);
        if (!$doc || !$doc->embedding) return [];

        // Use CTE + ORDER BY for HNSW index usage; filter threshold in PHP
        $results = DB::select("
            WITH ref AS (SELECT embedding FROM content_documents WHERE id = ?)
            SELECT id, source_type, source_id, creator_id,
                   1 - (embedding <=> (SELECT embedding FROM ref)) as similarity
            FROM content_documents
            WHERE id != ?
              AND embedding IS NOT NULL
              AND content_tier != 'blackhole'
            ORDER BY embedding <=> (SELECT embedding FROM ref) ASC
            LIMIT ?
        ", [$docId, $docId, $limit]);

        // Filter by threshold in PHP (WHERE clause arithmetic prevents HNSW index usage)
        return array_map(
            fn($r) => (array) $r,
            array_filter($results, fn($r) => $r->similarity >= $threshold)
        );
    }

    /**
     * Check a single document for duplicates and apply penalties.
     * - Newer duplicate gets quality_score penalty (-3 points)
     * - Same creator duplicating → potential spam flag
     * - Older document treated as canonical (no penalty)
     */
    public static function checkAndPenalize(int $docId, float $threshold = 0.95): array
    {
        $doc = ContentDocument::find($docId);
        if (!$doc) return ['checked' => false, 'duplicates' => 0];

        $duplicates = self::findDuplicates($docId, $threshold);
        $penalized = 0;

        foreach ($duplicates as $dup) {
            $dupDoc = ContentDocument::find($dup['id']);
            if (!$dupDoc) continue;

            // Newer duplicate gets penalized
            if ($doc->published_at && $dupDoc->published_at) {
                if ($doc->published_at > $dupDoc->published_at) {
                    // This doc is newer → penalize it
                    $doc->quality_score = max(0, $doc->quality_score - 3);

                    // Same creator = potential spam
                    if ($doc->creator_id === $dupDoc->creator_id) {
                        $doc->spam_score = min(10, $doc->spam_score + 2);
                        Log::info("Duplicate detection: same-creator duplicate flagged", [
                            'doc' => $docId,
                            'duplicate_of' => $dup['id'],
                            'creator' => $doc->creator_id,
                            'similarity' => $dup['similarity'],
                        ]);
                    }

                    $doc->recomputeCompositeAndTier(save: true);
                    $penalized++;
                }
            }
        }

        return [
            'checked' => true,
            'duplicates' => count($duplicates),
            'penalized' => $penalized,
        ];
    }

    /**
     * Batch scan recent documents for duplicates.
     * Used by the scheduled command.
     */
    public static function batchScan(int $hours = 24, float $threshold = 0.95, int $batchSize = 100): array
    {
        $stats = ['scanned' => 0, 'duplicates_found' => 0, 'penalized' => 0];

        ContentDocument::where('published_at', '>=', now()->subHours($hours))
            ->whereNotNull('embedding')
            ->where('content_tier', '!=', 'blackhole')
            ->orderBy('id', 'desc')
            ->chunk($batchSize, function ($docs) use (&$stats, $threshold) {
                foreach ($docs as $doc) {
                    $result = self::checkAndPenalize($doc->id, $threshold);
                    $stats['scanned']++;
                    $stats['duplicates_found'] += $result['duplicates'] ?? 0;
                    $stats['penalized'] += $result['penalized'] ?? 0;
                }
            });

        return $stats;
    }
}
