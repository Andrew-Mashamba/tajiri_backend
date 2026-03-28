<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;

class ReRankerService
{
    /**
     * Re-rank scored candidates applying twiddlers.
     *
     * @param array<int, array{doc: ContentDocument, score: float, boosts: array}> $scored
     * @return array<int, array{doc: ContentDocument, score: float, boosts: array, context: array}>
     */
    public static function rerank(array $scored, int $userId, string $feedType): array
    {
        $config = config('content-engine.serving.reranker');
        $isNewUser = self::isNewUser($userId, $config['new_user_days']);
        $explorationPct = $isNewUser ? $config['exploration_pct_new_user'] : $config['exploration_pct'];

        // Apply freshness boost to scores
        $scored = self::applyFreshnessBoost($scored, $config);

        // Apply streak bonus
        $scored = self::applyStreakBonus($scored, $config);

        // Re-sort after score modifications
        uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Apply diversity rules and build final list
        $result = [];
        $deferred = [];
        $creatorCount = [];
        $typeCount = [];
        $consecutiveCreator = null;
        $consecutiveCreatorRun = 0;
        $consecutiveType = null;
        $consecutiveTypeRun = 0;
        $explorationSlots = [];
        $normalSlots = [];

        // Separate exploration candidates
        // NOTE: Anti-bubble ("outside interest profile") overlaps with exploration ("unseen creators/categories").
        // The current exploration implementation covers both use cases — items from unknown creators AND
        // unknown categories are all placed in the exploration pool. No separate anti-bubble pass needed.
        $userCreators = UserSignalService::getTopCreators($userId, 50);
        $userCategories = UserSignalService::getTopCategories($userId, 20);

        foreach ($scored as $docId => $item) {
            $doc = $item['doc'];
            $isExploration = !in_array($doc->creator_id, $userCreators)
                          && !in_array($doc->category, $userCategories);

            if ($isExploration) {
                $item['context'] = ['reason' => 'exploration', 'is_exploration' => true, 'is_sponsored' => false];
                $explorationSlots[$docId] = $item;
            } else {
                $item['context'] = ['reason' => self::classifyReason($item, $feedType), 'is_exploration' => false, 'is_sponsored' => false];
                $normalSlots[$docId] = $item;
            }
        }

        // Build final list with diversity enforcement
        $totalSlots = count($scored);
        $explorationTarget = (int) ceil($totalSlots * $explorationPct);

        $explorationInserted = 0;
        $normalIterator = new \ArrayIterator($normalSlots);
        $explorationIterator = new \ArrayIterator($explorationSlots);
        $position = 0;

        while ($position < $totalSlots && ($normalIterator->valid() || $explorationIterator->valid())) {
            $position++;

            // Insert exploration at every 1/explorationPct interval
            if ($explorationInserted < $explorationTarget && $explorationIterator->valid()
                && ($position % max(1, (int)(1 / $explorationPct)) === 0)) {
                $item = $explorationIterator->current();
                $explorationIterator->next();
                $explorationInserted++;
            } elseif ($normalIterator->valid()) {
                $item = $normalIterator->current();
                $normalIterator->next();
            } elseif ($explorationIterator->valid()) {
                $item = $explorationIterator->current();
                $explorationIterator->next();
            } else {
                break;
            }

            $doc = $item['doc'];

            // Diversity: defer if too many consecutive same creator
            if ($doc->creator_id === $consecutiveCreator) {
                $consecutiveCreatorRun++;
                if ($consecutiveCreatorRun > $config['max_consecutive_same_creator']) {
                    $deferred[] = $item;
                    continue;
                }
            } else {
                $consecutiveCreator = $doc->creator_id;
                $consecutiveCreatorRun = 1;
            }

            // Diversity: defer if too many consecutive same type
            if ($doc->source_type === $consecutiveType) {
                $consecutiveTypeRun++;
                if ($consecutiveTypeRun > $config['max_consecutive_same_type']) {
                    $deferred[] = $item;
                    continue;
                }
            } else {
                $consecutiveType = $doc->source_type;
                $consecutiveTypeRun = 1;
            }

            $result[] = $item;
        }

        // Re-insert deferred items at the end (diversity-violated items still appear, just later)
        foreach ($deferred as $item) {
            $result[] = $item;
        }

        return $result;
    }

    private static function applyFreshnessBoost(array $scored, array $config): array
    {
        $now = now();
        foreach ($scored as $docId => &$item) {
            $publishedAt = $item['doc']->published_at;
            if (!$publishedAt) continue;

            $minutesAgo = $now->diffInMinutes($publishedAt);
            if ($minutesAgo < 15) {
                $item['score'] *= $config['freshness_15min_boost'];
            } elseif ($minutesAgo < 60) {
                $item['score'] *= $config['freshness_1h_boost'];
            }
        }
        return $scored;
    }

    private static function applyStreakBonus(array $scored, array $config): array
    {
        // Collect unique creator IDs to batch check streaks
        $creatorIds = array_unique(array_filter(
            array_map(fn($item) => $item['doc']->creator_id, $scored)
        ));

        if (empty($creatorIds)) return $scored;

        // Check creator_scores for active streaks
        $streakCreators = \Illuminate\Support\Facades\DB::table('creator_scores')
            ->whereIn('user_id', $creatorIds)
            ->where('consistency_score', '>=', 0.5)
            ->pluck('user_id')
            ->all();

        if (empty($streakCreators)) return $scored;

        foreach ($scored as $docId => &$item) {
            if (in_array($item['doc']->creator_id, $streakCreators)) {
                $item['score'] *= $config['streak_bonus'];
            }
        }

        return $scored;
    }

    private static function isNewUser(int $userId, int $days): bool
    {
        $user = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $userId)
            ->value('created_at');

        return $user && now()->diffInDays($user) <= $days;
    }

    private static function classifyReason(array $item, string $feedType): string
    {
        $boosts = $item['boosts'] ?? [];

        if ($feedType === 'trending') return 'trending';
        if ($feedType === 'friends') return 'friend_activity';
        if (($boosts['social'] ?? 0) >= 15) return 'friend_activity';
        if (($boosts['creator'] ?? 0) >= 10) return 'favorite_creator';
        if (($boosts['category'] ?? 0) >= 7) return 'interest_match';
        if (($item['doc']->trending_score ?? 0) > 50) return 'trending_in_region';

        return 'recommended';
    }
}
