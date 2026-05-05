<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CandidateGeneratorService
{
    /**
     * Generate candidates for a feed request.
     * Fan-out to multiple sources based on feed type config.
     *
     * @return array<int, ContentDocument> keyed by doc ID
     */
    public static function generate(string $feedType, int $userId, array $options = []): array
    {
        $sources = config("content-engine.serving.feed_sources.{$feedType}", []);
        $candidates = [];

        foreach ($sources as $source => $limit) {
            try {
                $docs = match ($source) {
                    'typesense' => self::fromTypesense($feedType, $userId, $limit, $options),
                    'pgvector' => self::fromPgvector($userId, $limit, $options),
                    'trending' => self::fromTrending($userId, $limit, $options),
                    'personal' => self::fromPersonal($userId, $limit),
                    'social' => self::fromSocial($userId, $limit),
                    default => [],
                };
                foreach ($docs as $doc) {
                    $candidates[$doc->id] = $doc;
                }
            } catch (\Throwable $e) {
                Log::warning("CandidateGenerator: source {$source} failed", [
                    'feed_type' => $feedType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $candidates;
    }

    /**
     * Generate candidates for a search request.
     */
    public static function generateForSearch(string $query, int $userId, array $filters = []): array
    {
        $limits = config('content-engine.serving.feed_sources.search', []);
        $candidates = [];

        // Typesense keyword search
        if ($limit = $limits['typesense'] ?? 200) {
            try {
                $ids = TypesenseService::searchIds($query, $limit, $filters);
                if (!empty($ids)) {
                    $docs = ContentDocument::whereIn('id', $ids)->get()->keyBy('id');
                    foreach ($docs as $doc) {
                        $candidates[$doc->id] = $doc;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("CandidateGenerator: typesense search failed", ['error' => $e->getMessage()]);
            }
        }

        // pgvector semantic search (if query has embedding)
        if ($limit = $limits['pgvector'] ?? 100) {
            try {
                $semanticDocs = self::semanticSearch($query, $limit);
                foreach ($semanticDocs as $doc) {
                    $candidates[$doc->id] = $doc;
                }
            } catch (\Throwable $e) {
                Log::warning("CandidateGenerator: semantic search failed", ['error' => $e->getMessage()]);
            }
        }

        // Trending boost
        if ($limit = $limits['trending'] ?? 30) {
            try {
                $trendingDocs = self::fromTrending($userId, $limit, []);
                foreach ($trendingDocs as $doc) {
                    $candidates[$doc->id] = $doc;
                }
            } catch (\Throwable $e) {
                Log::warning("CandidateGenerator: trending for search failed", ['error' => $e->getMessage()]);
            }
        }

        return $candidates;
    }

    /**
     * Typesense keyword candidates.
     */
    private static function fromTypesense(string $feedType, int $userId, int $limit, array $options): array
    {
        $filters = [];

        // Feed-type-specific filters
        if ($feedType === 'shorts') {
            $filters['source_type'] = 'clip';
        } elseif ($feedType === 'audio') {
            $filters['source_type'] = 'music';
        }

        $query = $options['query'] ?? '*';
        $ids = TypesenseService::searchIds($query, $limit, $filters);

        if (empty($ids)) return [];

        return ContentDocument::whereIn('id', $ids)
            ->where('content_tier', '!=', 'blackhole')
            ->get()
            ->all();
    }

    /**
     * pgvector semantic similarity candidates.
     * Uses the user's recent engagement to find similar content.
     */
    private static function fromPgvector(int $userId, int $limit, array $options): array
    {
        // Get user's most recently engaged document as the seed
        $seedDocId = self::getUserSeedDocument($userId);
        if (!$seedDocId) {
            // Fallback: use highest-ranked recent content
            return ContentDocument::where('content_tier', '!=', 'blackhole')
                ->whereNotNull('embedding')
                ->where('published_at', '>=', now()->subDays(7))
                ->orderByDesc('composite_score')
                ->limit($limit)
                ->get()
                ->all();
        }

        // Find similar via pgvector
        $results = DB::select("
            WITH ref AS (SELECT embedding FROM content_documents WHERE id = ?)
            SELECT id FROM content_documents
            WHERE id != ?
              AND embedding IS NOT NULL
              AND content_tier != 'blackhole'
              AND published_at >= NOW() - INTERVAL '14 days'
            ORDER BY embedding <=> (SELECT embedding FROM ref) ASC
            LIMIT ?
        ", [$seedDocId, $seedDocId, $limit]);

        if (empty($results)) return [];

        $ids = array_map(fn($r) => $r->id, $results);
        return ContentDocument::whereIn('id', $ids)->get()->all();
    }

    /**
     * Redis trending candidates.
     */
    private static function fromTrending(int $userId, int $limit, array $options): array
    {
        $region = $options['region'] ?? null;
        $key = $region ? "trending:region:{$region}" : 'trending:global';

        $docIds = Redis::zrevrange($key, 0, $limit - 1);
        if (empty($docIds)) return [];

        // Redis returns string IDs
        $docIds = array_map('intval', $docIds);

        return ContentDocument::whereIn('id', $docIds)
            ->where('content_tier', '!=', 'blackhole')
            ->get()
            ->all();
    }

    /**
     * Personalized candidates from user's affinity profile.
     */
    private static function fromPersonal(int $userId, int $limit): array
    {
        // Get user's top creators and categories from UserSignalService
        $creatorIds = UserSignalService::getTopCreators($userId, 10);
        $categories = UserSignalService::getTopCategories($userId, 5);

        if (empty($creatorIds) && empty($categories)) return [];

        $query = ContentDocument::where('content_tier', '!=', 'blackhole')
            ->where('published_at', '>=', now()->subDays(7));

        if (!empty($creatorIds) && !empty($categories)) {
            $query->where(function ($q) use ($creatorIds, $categories) {
                $q->whereIn('creator_id', $creatorIds)
                  ->orWhereIn('category', $categories);
            });
        } elseif (!empty($creatorIds)) {
            $query->whereIn('creator_id', $creatorIds);
        } else {
            $query->whereIn('category', $categories);
        }

        return $query->orderByDesc('composite_score')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Social graph candidates (friends' content).
     */
    private static function fromSocial(int $userId, int $limit): array
    {
        // Get friend IDs from friendships table
        $friendIds = DB::table('friendships')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('friend_id', $userId);
            })
            ->where('status', 'accepted')
            ->get()
            ->map(fn($f) => $f->user_id == $userId ? $f->friend_id : $f->user_id)
            ->unique()
            ->values()
            ->all();

        if (empty($friendIds)) return [];

        return ContentDocument::whereIn('creator_id', $friendIds)
            ->where('content_tier', '!=', 'blackhole')
            ->where('published_at', '>=', now()->subDays(14))
            ->orderByDesc('composite_score')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Semantic search: embed query text, then find similar documents.
     */
    private static function semanticSearch(string $query, int $limit): array
    {
        // Call embedding service to get query vector
        try {
            $response = Http::timeout(5)
                ->post(config('content-engine.embedding_service_url', 'http://127.0.0.1:8200') . '/embed', [
                    'text' => $query,
                ]);

            if (!$response->ok()) return [];

            $embedding = $response->json('embedding');
            if (!$embedding) return [];

            $vectorStr = '[' . implode(',', $embedding) . ']';

            $results = DB::select("
                SELECT id FROM content_documents
                WHERE embedding IS NOT NULL
                  AND content_tier != 'blackhole'
                ORDER BY embedding <=> ?::vector ASC
                LIMIT ?
            ", [$vectorStr, $limit]);

            if (empty($results)) return [];

            $ids = array_map(fn($r) => $r->id, $results);
            return ContentDocument::whereIn('id', $ids)->get()->all();
        } catch (\Throwable $e) {
            Log::warning("CandidateGenerator: semantic search embedding failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a seed document ID based on user's recent engagement.
     */
    private static function getUserSeedDocument(int $userId): ?int
    {
        // Get user's most recent liked/viewed content
        $event = DB::table('user_events')
            ->where('user_id', $userId)
            ->whereIn('event_type', ['like', 'view', 'share'])
            ->orderByDesc('created_at')
            ->first();

        if (!$event) return null;

        return ContentDocument::where('source_type', $event->source_type ?? 'post')
            ->where('source_id', $event->source_id ?? $event->post_id)
            ->value('id');
    }
}
