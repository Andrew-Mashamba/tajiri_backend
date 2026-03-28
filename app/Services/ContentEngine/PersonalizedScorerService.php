<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PersonalizedScorerService
{
    /**
     * Score an array of candidates for a specific user.
     * Adds personalized_score to each document.
     *
     * @param array<int, ContentDocument> $candidates keyed by doc ID
     * @return array<int, array{doc: ContentDocument, score: float}>
     */
    public static function score(array $candidates, int $userId): array
    {
        if (empty($candidates)) return [];

        $config = config('content-engine.serving.personalized_scoring');

        // Load user signals
        $topCreators = UserSignalService::getTopCreators($userId, 50);
        $topCategories = UserSignalService::getTopCategories($userId, 20);
        $topHashtags = UserSignalService::getTopHashtags($userId, 30);
        $mediaPrefs = UserSignalService::getMediaPreferences($userId);
        $friendIds = self::getFriendIds($userId);
        $fofIds = self::getFriendOfFriendIds($userId, $friendIds);
        $userRegion = self::getUserRegion($userId);

        $scored = [];

        foreach ($candidates as $docId => $doc) {
            $base = $doc->composite_score ?? 0;

            // Creator affinity
            $creatorBoost = 0;
            if ($doc->creator_id && in_array($doc->creator_id, $topCreators)) {
                $rank = array_search($doc->creator_id, $topCreators);
                $creatorBoost = $config['creator_affinity_max'] * (1 - ($rank / max(count($topCreators), 1)));
            }

            // Category affinity
            $categoryBoost = 0;
            if ($doc->category && in_array($doc->category, $topCategories)) {
                $rank = array_search($doc->category, $topCategories);
                $categoryBoost = $config['category_affinity_max'] * (1 - ($rank / max(count($topCategories), 1)));
            }

            // Hashtag affinity
            $hashtagBoost = 0;
            $docHashtags = is_array($doc->hashtags) ? $doc->hashtags : [];
            if (!empty($docHashtags) && !empty($topHashtags)) {
                $overlap = count(array_intersect($docHashtags, $topHashtags));
                $hashtagBoost = min($config['hashtag_affinity_max'], $overlap * 2);
            }

            // Media preference
            $mediaBoost = 0;
            $docMediaTypes = is_array($doc->media_types) ? $doc->media_types : [];
            if (!empty($docMediaTypes) && !empty($mediaPrefs)) {
                foreach ($docMediaTypes as $mt) {
                    if (in_array($mt, $mediaPrefs)) {
                        $mediaBoost = $config['media_preference_max'];
                        break;
                    }
                }
            }

            // Social proximity
            $socialBoost = 0;
            if ($doc->creator_id) {
                if (in_array($doc->creator_id, $friendIds)) {
                    $socialBoost = $config['social_proximity_friend'];
                } elseif (in_array($doc->creator_id, $fofIds)) {
                    $socialBoost = $config['social_proximity_fof'];
                }
            }

            // Regional proximity
            $regionalBoost = 0;
            if ($userRegion && $doc->region_name) {
                if ($doc->region_name === $userRegion['region']) {
                    $regionalBoost = $config['regional_same_region'];
                }
                if (isset($userRegion['district']) && $doc->district_name === $userRegion['district']) {
                    $regionalBoost += $config['regional_same_district'];
                }
            }

            $personalizedScore = $base + $creatorBoost + $categoryBoost + $hashtagBoost
                               + $mediaBoost + $socialBoost + $regionalBoost;

            $scored[$docId] = [
                'doc' => $doc,
                'score' => round($personalizedScore, 2),
                'boosts' => [
                    'base' => $base,
                    'creator' => round($creatorBoost, 1),
                    'category' => round($categoryBoost, 1),
                    'hashtag' => round($hashtagBoost, 1),
                    'media' => round($mediaBoost, 1),
                    'social' => round($socialBoost, 1),
                    'regional' => round($regionalBoost, 1),
                ],
            ];
        }

        // Sort by personalized score descending
        uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    private static function getFriendIds(int $userId): array
    {
        return DB::table('friendships')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('friend_id', $userId);
            })
            ->where('status', 'accepted')
            ->get()
            ->map(fn($f) => $f->user_id == $userId ? $f->friend_id : $f->user_id)
            ->unique()
            ->values()
            ->all();
    }

    private static function getFriendOfFriendIds(int $userId, array $friendIds): array
    {
        if (empty($friendIds)) return [];

        return DB::table('friendships')
            ->where(function ($q) use ($friendIds) {
                $q->whereIn('user_id', $friendIds)->orWhereIn('friend_id', $friendIds);
            })
            ->where('status', 'accepted')
            ->get()
            ->map(fn($f) => in_array($f->user_id, $friendIds) ? $f->friend_id : $f->user_id)
            ->unique()
            ->reject(fn($id) => $id == $userId || in_array($id, $friendIds))
            ->values()
            ->take(200)
            ->all();
    }

    private static function getUserRegion(int $userId): ?array
    {
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first(['region', 'district']);
        if (!$profile) return null;
        return ['region' => $profile->region, 'district' => $profile->district ?? null];
    }
}
