<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServingPipelineService
{
    /**
     * Serve a feed request through the full pipeline.
     */
    public static function serveFeed(string $feedType, int $userId, int $page = 1, int $perPage = 20): array
    {
        $startTime = microtime(true);

        // Step 0: Check cache
        $cached = FeedCacheService::getFeed($userId, $feedType, $perPage, $page);
        if ($cached) {
            $cached['meta']['served_from_cache'] = true;
            return $cached;
        }

        // TODO: Cache ranked candidate IDs for pagination efficiency
        // Currently the full pipeline re-runs for page > 1. Acceptable for MVP.

        // Step 1: No query understanding needed for feeds

        // Step 2: Candidate generation
        $options = [];

        // For 'nearby' feed, detect user's region and pass as option
        if ($feedType === 'nearby') {
            $profile = DB::table('user_profiles')->where('id', $userId)->first(['region_name', 'district_name']);
            if ($profile && $profile->region_name) {
                $options['region'] = $profile->region_name;
            }
        }

        $candidates = CandidateGeneratorService::generate($feedType, $userId, $options);

        // Step 3: Merge, dedup, privacy filter
        $candidates = self::filterPrivacyAndBlocked($candidates, $userId);

        // For 'discover' feed, exclude friends' content to ensure diverse discovery
        if ($feedType === 'discover') {
            $friendIds = DB::table('friendships')
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhere('friend_id', $userId);
                })
                ->where('status', 'accepted')
                ->get()
                ->map(fn($f) => $f->user_id == $userId ? $f->friend_id : $f->user_id)
                ->unique()
                ->all();
            if (!empty($friendIds)) {
                $candidates = array_filter($candidates, function (ContentDocument $doc) use ($friendIds) {
                    return !in_array($doc->creator_id, $friendIds);
                });
            }
        }

        // Step 4: Personalized scoring
        $scored = PersonalizedScorerService::score($candidates, $userId);

        // Step 5: Re-rank with twiddlers
        $reranked = ReRankerService::rerank($scored, $userId, $feedType);

        // Step 6: Paginate
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($reranked, $offset, $perPage);

        // Step 7: Hydrate full source records
        $hydrated = self::hydrate($pageItems);

        $queryTimeMs = round((microtime(true) - $startTime) * 1000);

        $result = [
            'success' => true,
            'data' => $hydrated,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_candidates' => count($reranked),
                'served_from_cache' => false,
                'query_time_ms' => $queryTimeMs,
                'feed_type' => $feedType,
            ],
        ];

        // Cache the result
        FeedCacheService::setFeed($userId, $feedType, $perPage, $page, $result);

        return $result;
    }

    /**
     * Serve a search request through the full pipeline.
     */
    public static function serveSearch(
        string $query, int $userId, int $page = 1, int $perPage = 20,
        array $filters = []
    ): array {
        $startTime = microtime(true);

        // Step 0: Check cache (per-user because results are personalized)
        $cached = FeedCacheService::getSearch($userId, $query, $filters, $page);
        if ($cached) {
            $cached['meta']['served_from_cache'] = true;
            return $cached;
        }

        // Step 1: Query understanding (Typesense handles keyword matching)
        // Claude query expansion deferred to Phase 5 (AI Layer)

        // Step 2: Candidate generation
        $candidates = CandidateGeneratorService::generateForSearch($query, $userId, $filters);

        // Step 3: Privacy filter
        $candidates = self::filterPrivacyAndBlocked($candidates, $userId);

        // Step 4: Personalized scoring
        $scored = PersonalizedScorerService::score($candidates, $userId);

        // Step 5: Re-rank
        $reranked = ReRankerService::rerank($scored, $userId, 'search');

        // Step 6: Paginate
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($reranked, $offset, $perPage);

        // Step 7: Hydrate
        $hydrated = self::hydrate($pageItems);

        $queryTimeMs = round((microtime(true) - $startTime) * 1000);

        $result = [
            'success' => true,
            'data' => $hydrated,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_candidates' => count($reranked),
                'served_from_cache' => false,
                'query_time_ms' => $queryTimeMs,
                'query' => $query,
            ],
        ];

        // Cache search results (per-user)
        FeedCacheService::setSearch($userId, $query, $filters, $page, $result);

        return $result;
    }

    /**
     * Privacy filtering: remove documents from blocked users,
     * private docs, and friends-only docs from non-friends.
     */
    private static function filterPrivacyAndBlocked(array $candidates, int $userId): array
    {
        if (empty($candidates)) return [];

        // Get blocked user IDs (both directions)
        $blockedIds = DB::table('blocked_users')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('blocked_user_id', $userId);
            })
            ->get()
            ->map(fn($b) => $b->user_id == $userId ? $b->blocked_user_id : $b->user_id)
            ->unique()
            ->all();

        // Get friend IDs for friends-only filter
        $friendIds = DB::table('friendships')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('friend_id', $userId);
            })
            ->where('status', 'accepted')
            ->get()
            ->map(fn($f) => $f->user_id == $userId ? $f->friend_id : $f->user_id)
            ->unique()
            ->all();

        return array_filter($candidates, function (ContentDocument $doc) use ($userId, $blockedIds, $friendIds) {
            // Remove blocked users' content
            if ($doc->creator_id && in_array($doc->creator_id, $blockedIds)) {
                return false;
            }

            // Remove private content (only visible on creator's profile)
            if ($doc->privacy === 'private' && $doc->creator_id !== $userId) {
                return false;
            }

            // Remove friends-only content from non-friends
            if ($doc->privacy === 'friends' && $doc->creator_id !== $userId
                && !in_array($doc->creator_id, $friendIds)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Hydrate documents with full source records.
     * Returns the API response format.
     */
    private static function hydrate(array $items): array
    {
        $hydrated = [];

        // Group by source_type for batch loading
        $byType = [];
        foreach ($items as $item) {
            $doc = $item['doc'];
            $byType[$doc->source_type][$doc->source_id] = $item;
        }

        // Model mapping for hydration
        $modelMap = [
            'post' => \App\Models\Post::class,
            'clip' => \App\Models\Clip::class,
            'story' => \App\Models\Story::class,
            'music' => \App\Models\MusicTrack::class,
            'stream' => \App\Models\LiveStream::class,
            'event' => \App\Models\Event::class,
            'campaign' => \App\Models\Campaign::class,
            'product' => \App\Models\Shop\Product::class,
            'group' => \App\Models\Group::class,
            'gossip_thread' => \App\Models\GossipThread::class,
            'user_profile' => \App\Models\UserProfile::class,
        ];

        foreach ($byType as $sourceType => $itemsBySourceId) {
            $modelClass = $modelMap[$sourceType] ?? null;
            if (!$modelClass) continue;

            $sourceIds = array_keys($itemsBySourceId);
            $sources = $modelClass::whereIn('id', $sourceIds)->get()->keyBy('id');

            foreach ($itemsBySourceId as $sourceId => $item) {
                $source = $sources->get($sourceId);
                if (!$source) continue;

                $doc = $item['doc'];
                $hydrated[] = [
                    'document' => [
                        'id' => $doc->id,
                        'source_type' => $doc->source_type,
                        'source_id' => $doc->source_id,
                        'title' => $doc->title,
                        'content_tier' => $doc->content_tier,
                        'scores' => [
                            'composite' => $doc->composite_score,
                            'personalized' => $item['score'],
                            'trending' => $doc->trending_score ?? 0,
                        ],
                    ],
                    'source' => $source->toArray(),
                    'context' => $item['context'] ?? [
                        'reason' => 'recommended',
                        'is_sponsored' => false,
                        'is_exploration' => false,
                    ],
                ];
            }
        }

        // Re-sort by personalized score to maintain order after batch hydration
        usort($hydrated, fn($a, $b) => ($b['document']['scores']['personalized'] ?? 0) <=> ($a['document']['scores']['personalized'] ?? 0));

        return $hydrated;
    }
}
