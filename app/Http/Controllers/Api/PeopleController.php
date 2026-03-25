<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedUser;
use App\Models\Friendship;
use App\Models\UserPresence;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeopleController extends Controller
{
    private const VALID_SORT_KEYS = [
        'relevance', 'newest', 'last_seen', 'most_active', 'friends_count',
        'least_connected', 'most_mutual_friends', 'similar_to_me', 'single_first',
        'same_area_first', 'most_shared_interests', 'least_male_friends',
        'least_female_friends', 'verified', 'possible_business_connection',
        'possible_employer',
    ];

    private const VALID_RELATIONSHIP_STATUSES = [
        'single', 'in_relationship', 'engaged', 'married',
        'complicated', 'divorced', 'widowed',
    ];

    /**
     * GET /api/people/search
     */
    public function search(Request $request): JsonResponse
    {
        // ── Parse input ────────────────────────────────────────────
        $q = trim($request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $sort = $request->query('sort', 'relevance');

        // Filters
        $filters = [
            'gender'                      => $request->query('gender'),
            'online'                      => $request->query('online'),
            'location'                    => $request->query('location'),
            'employer'                    => $request->query('employer'),
            'school'                      => $request->query('school'),
            'has_photo'                   => $request->query('has_photo'),
            'age_min'                     => $request->query('age_min'),
            'age_max'                     => $request->query('age_max'),
            'relationship_status'         => $request->query('relationship_status'),
            'sector'                      => $request->query('sector'),
            'student'                     => $request->query('student'),
            'has_interests'               => $request->query('has_interests'),
            'profile_complete'            => $request->query('profile_complete'),
            'friends_of_friends_only'     => $request->query('friends_of_friends_only'),
            'verified'                    => $request->query('verified'),
            'possible_business_connection'=> $request->query('possible_business_connection'),
            'possible_employer'           => $request->query('possible_employer'),
        ];

        $hasAnyFilter = !empty(array_filter($filters));
        $isDiscovery = strlen($q) < 2 && !$hasAnyFilter;

        // ── Viewer context ─────────────────────────────────────────
        $userId = $request->query('user_id') ?? $request->user()?->id;
        $userId = $userId ? (int) $userId : null;

        $blockedIds = $userId ? BlockedUser::getAllBlockedIds($userId) : [];
        $myProfile = null;
        $myFriendIds = [];
        if ($userId) {
            $myProfile = UserProfile::find($userId);
            if ($myProfile) {
                $myFriendIds = $myProfile->getFriendIds();
            }
        }

        // ── Build query ────────────────────────────────────────────
        $query = UserProfile::query()->select('user_profiles.*');

        $this->applyExclusions($query, $userId, $blockedIds);
        $this->applyPrivacy($query, $userId, $myFriendIds);
        $this->applyTextSearch($query, $q);
        $this->applyFilters($query, $filters, $userId, $myFriendIds);

        if ($isDiscovery) {
            $this->applyDiscoverySort($query, $userId, $myFriendIds, $myProfile);
        } else {
            $this->applySorts($query, $sort, $userId, $myFriendIds, $myProfile, $q);
        }

        // ── Paginate ───────────────────────────────────────────────
        $results = $query->paginate($perPage, ['*'], 'page', $page);

        // ── Enrich results ─────────────────────────────────────────
        $data = $this->enrichResults($results, $userId, $myProfile, $myFriendIds);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    // ================================================================
    //  Query building helpers
    // ================================================================

    private function applyExclusions(Builder $query, ?int $userId, array $blockedIds): void
    {
        if (!empty($blockedIds)) {
            $query->whereNotIn('user_profiles.id', $blockedIds);
        }
        if ($userId) {
            $query->where('user_profiles.id', '!=', $userId);
        }
    }

    private function applyPrivacy(Builder $query, ?int $userId, array $myFriendIds): void
    {
        $query->where(function ($qb) use ($userId, $myFriendIds) {
            // Everyone or NULL = visible
            $qb->whereNull('profile_visibility')
                ->orWhere('profile_visibility', 'everyone');

            // Friends-only profiles: visible if viewer is a friend
            if ($userId && !empty($myFriendIds)) {
                $qb->orWhere(function ($inner) use ($myFriendIds) {
                    $inner->where('profile_visibility', 'friends')
                          ->whereIn('user_profiles.id', $myFriendIds);
                });
            }
            // profile_visibility='nobody' is implicitly excluded
        });
    }

    private function applyTextSearch(Builder $query, string $q): void
    {
        if (strlen($q) < 2) {
            return;
        }

        $query->where(function ($qb) use ($q) {
            $qb->where('first_name', 'ILIKE', "%{$q}%")
                ->orWhere('last_name', 'ILIKE', "%{$q}%")
                ->orWhere('username', 'ILIKE', "%{$q}%")
                ->orWhere('bio', 'ILIKE', "%{$q}%")
                ->orWhere('region_name', 'ILIKE', "%{$q}%")
                ->orWhere('district_name', 'ILIKE', "%{$q}%")
                ->orWhere('primary_school_name', 'ILIKE', "%{$q}%")
                ->orWhere('secondary_school_name', 'ILIKE', "%{$q}%")
                ->orWhere('alevel_school_name', 'ILIKE', "%{$q}%")
                ->orWhere('university_name', 'ILIKE', "%{$q}%")
                ->orWhere('postsecondary_name', 'ILIKE', "%{$q}%")
                ->orWhere('employer_name', 'ILIKE', "%{$q}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$q}%"]);
        });
    }

    private function applyFilters(Builder $query, array $f, ?int $userId, array $myFriendIds): void
    {
        // Gender
        if (!empty($f['gender']) && in_array(strtolower($f['gender']), ['male', 'female'])) {
            $query->where('gender', strtolower($f['gender']));
        }

        // Online only
        if (!empty($f['online'])) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('user_presence')
                    ->whereColumn('user_presence.user_id', 'user_profiles.id')
                    ->where('user_presence.is_online', true);
            });
        }

        // Location
        if (!empty($f['location'])) {
            $loc = $f['location'];
            $query->where(function ($qb) use ($loc) {
                $qb->where('region_name', 'ILIKE', "%{$loc}%")
                    ->orWhere('district_name', 'ILIKE', "%{$loc}%");
            });
        }

        // Employer
        if (!empty($f['employer'])) {
            $query->where('employer_name', 'ILIKE', "%{$f['employer']}%");
        }

        // School (any level)
        if (!empty($f['school'])) {
            $sch = $f['school'];
            $query->where(function ($qb) use ($sch) {
                $qb->where('primary_school_name', 'ILIKE', "%{$sch}%")
                    ->orWhere('secondary_school_name', 'ILIKE', "%{$sch}%")
                    ->orWhere('alevel_school_name', 'ILIKE', "%{$sch}%")
                    ->orWhere('postsecondary_name', 'ILIKE', "%{$sch}%")
                    ->orWhere('university_name', 'ILIKE', "%{$sch}%");
            });
        }

        // Has profile photo
        if (!empty($f['has_photo'])) {
            $query->whereNotNull('profile_photo_path')
                  ->where('profile_photo_path', '!=', '');
        }

        // Age range
        if (!empty($f['age_min'])) {
            $maxDob = now()->subYears((int) $f['age_min'])->toDateString();
            $query->where('date_of_birth', '<=', $maxDob);
        }
        if (!empty($f['age_max'])) {
            $minDob = now()->subYears((int) $f['age_max'] + 1)->addDay()->toDateString();
            $query->where('date_of_birth', '>=', $minDob);
        }

        // Relationship status
        if (!empty($f['relationship_status']) && in_array($f['relationship_status'], self::VALID_RELATIONSHIP_STATUSES)) {
            $query->where('relationship_status', $f['relationship_status']);
        }

        // Sector (employer_sector)
        if (!empty($f['sector'])) {
            $query->where('employer_sector', 'ILIKE', "%{$f['sector']}%");
        }

        // Student
        if (!empty($f['student'])) {
            $query->where('is_current_student', true);
        }

        // Has interests
        if (!empty($f['has_interests'])) {
            $query->whereNotNull('interests')
                  ->whereRaw("interests::text NOT IN ('[]', 'null', '')");
        }

        // Profile complete (photo + bio + at least one context field)
        if (!empty($f['profile_complete'])) {
            $query->whereNotNull('profile_photo_path')
                  ->where('profile_photo_path', '!=', '')
                  ->whereNotNull('bio')
                  ->where('bio', '!=', '')
                  ->where(function ($qb) {
                      $qb->whereNotNull('region_name')
                          ->orWhereNotNull('university_name')
                          ->orWhereNotNull('employer_name')
                          ->orWhereNotNull('secondary_school_name');
                  });
        }

        // Friends of friends only (2nd-degree connections)
        if (!empty($f['friends_of_friends_only']) && $userId && !empty($myFriendIds)) {
            $safeIds = array_map('intval', $myFriendIds);
            $friendIdList = implode(',', $safeIds);

            // Find users who are friends with any of my friends (but not me)
            $query->whereRaw("user_profiles.id IN (
                SELECT CASE WHEN user_id IN ({$friendIdList}) THEN friend_id ELSE user_id END
                FROM friendships
                WHERE status = 'accepted'
                  AND (user_id IN ({$friendIdList}) OR friend_id IN ({$friendIdList}))
                  AND user_id != ? AND friend_id != ?
            )", [$userId, $userId]);

            // Exclude direct friends (they are 1st degree, not 2nd)
            $query->whereNotIn('user_profiles.id', $safeIds);
        }

        // Verified
        if (!empty($f['verified'])) {
            $query->where('is_verified', true);
        }

        // Possible business connection
        if (!empty($f['possible_business_connection'])) {
            $query->whereNotNull('employer_name')
                  ->where('employer_name', '!=', '');
        }

        // Possible employer
        if (!empty($f['possible_employer'])) {
            $query->where(function ($qb) {
                $qb->whereNotNull('employer_name')
                    ->where('employer_name', '!=', '')
                    ->where(function ($inner) {
                        $inner->whereIn('employer_ownership', ['private', 'parastatal', 'government'])
                              ->orWhereNotNull('employer_sector');
                    });
            });
        }
    }

    // ================================================================
    //  Sort logic
    // ================================================================

    private function applySorts(Builder $query, string $sort, ?int $userId, array $myFriendIds, ?UserProfile $myProfile, string $q): void
    {
        $sortKeys = array_filter(array_map('trim', explode(',', $sort)));
        if (empty($sortKeys)) {
            $sortKeys = ['relevance'];
        }

        $presenceJoined = false;

        foreach ($sortKeys as $key) {
            if (!in_array($key, self::VALID_SORT_KEYS)) {
                continue;
            }
            $presenceJoined = $this->applySortKey($query, $key, $userId, $myFriendIds, $myProfile, $q, $presenceJoined);
        }
    }

    private function applyDiscoverySort(Builder $query, ?int $userId, array $myFriendIds, ?UserProfile $myProfile): void
    {
        // Discovery mode: no query, no filters — return a balanced mix of people.
        // Composite score weights multiple signals so results aren't dominated
        // by a single dimension (e.g. not *only* friends-of-friends).

        $scoreParts = [];
        $params = [];

        // ── 1. Friends-of-friends boost (weight 30) ──────────────────
        if ($userId && !empty($myFriendIds)) {
            $safeIds = array_map('intval', $myFriendIds);
            $friendIdList = implode(',', $safeIds);
            $scoreParts[] = "CASE WHEN user_profiles.id IN (
                SELECT CASE WHEN user_id IN ({$friendIdList}) THEN friend_id ELSE user_id END
                FROM friendships
                WHERE status = 'accepted'
                  AND (user_id IN ({$friendIdList}) OR friend_id IN ({$friendIdList}))
                  AND user_id != ? AND friend_id != ?
            ) THEN 30 ELSE 0 END";
            $params[] = $userId;
            $params[] = $userId;
        }

        // ── 2. Same area boost (weight 20 district, 10 region) ───────
        if ($myProfile?->district_name) {
            $scoreParts[] = "CASE WHEN district_name = ? THEN 20 ELSE 0 END";
            $params[] = $myProfile->district_name;
        }
        if ($myProfile?->region_name) {
            $scoreParts[] = "CASE WHEN region_name = ? THEN 10 ELSE 0 END";
            $params[] = $myProfile->region_name;
        }

        // ── 3. Same school/employer boost (weight 15 each) ───────────
        if ($myProfile?->university_name) {
            $scoreParts[] = "CASE WHEN LOWER(university_name) = LOWER(?) THEN 15 ELSE 0 END";
            $params[] = $myProfile->university_name;
        }
        if ($myProfile?->secondary_school_name) {
            $scoreParts[] = "CASE WHEN LOWER(secondary_school_name) = LOWER(?) THEN 15 ELSE 0 END";
            $params[] = $myProfile->secondary_school_name;
        }
        if ($myProfile?->employer_name) {
            $scoreParts[] = "CASE WHEN LOWER(employer_name) = LOWER(?) THEN 15 ELSE 0 END";
            $params[] = $myProfile->employer_name;
        }

        // ── 4. Recently active boost (weight 10) ─────────────────────
        $scoreParts[] = "CASE WHEN EXISTS (
            SELECT 1 FROM user_presence
            WHERE user_presence.user_id = user_profiles.id
              AND user_presence.last_seen_at > NOW() - INTERVAL '7 days'
        ) THEN 10 ELSE 0 END";

        // ── 5. Has profile photo (weight 5) ──────────────────────────
        $scoreParts[] = "CASE WHEN profile_photo_path IS NOT NULL AND profile_photo_path != '' THEN 5 ELSE 0 END";

        // ── 6. Verified boost (weight 5) ─────────────────────────────
        $scoreParts[] = "CASE WHEN is_verified = true THEN 5 ELSE 0 END";

        // ── 7. Profile completeness (weight 5) ──────────────────────
        $scoreParts[] = "CASE WHEN bio IS NOT NULL AND bio != '' THEN 5 ELSE 0 END";

        // ── Combine score + randomness for variety ───────────────────
        $scoreExpr = implode(' + ', $scoreParts);

        // Add a seeded random factor (0-15) so results shuffle per-day
        // but stay stable within the same day for consistent pagination.
        $daySeed = (int) now()->format('Ymd');
        $query->orderByRaw("({$scoreExpr}) + (hashtext(user_profiles.id::text || '{$daySeed}') % 15 + 15) % 15 DESC", $params);
    }

    private function applySortKey(
        Builder $query,
        string $key,
        ?int $userId,
        array $myFriendIds,
        ?UserProfile $myProfile,
        string $q,
        bool $presenceJoined,
    ): bool {
        switch ($key) {
            case 'relevance':
                $this->applyRelevanceSort($query, $userId, $myFriendIds, $q);
                break;

            case 'newest':
                $query->orderByDesc('user_profiles.created_at');
                break;

            case 'last_seen':
                if (!$presenceJoined) {
                    $query->leftJoin('user_presence', 'user_presence.user_id', '=', 'user_profiles.id');
                    $presenceJoined = true;
                }
                $query->orderByRaw('user_presence.last_seen_at DESC NULLS LAST');
                break;

            case 'most_active':
                $query->orderByDesc('user_profiles.posts_count');
                break;

            case 'friends_count':
                $query->orderByDesc('user_profiles.friends_count');
                break;

            case 'least_connected':
                $query->orderBy('user_profiles.friends_count', 'asc');
                break;

            case 'most_mutual_friends':
                $this->applySortMostMutualFriends($query, $myFriendIds);
                break;

            case 'similar_to_me':
                $this->applySortSimilarToMe($query, $myProfile);
                break;

            case 'single_first':
                $query->orderByRaw("CASE WHEN relationship_status = 'single' THEN 0 ELSE 1 END ASC");
                break;

            case 'same_area_first':
                $this->applySortSameAreaFirst($query, $myProfile);
                break;

            case 'most_shared_interests':
                $this->applySortMostSharedInterests($query, $myProfile);
                break;

            case 'least_male_friends':
                $this->applySortLeastGenderedFriends($query, 'male');
                break;

            case 'least_female_friends':
                $this->applySortLeastGenderedFriends($query, 'female');
                break;

            case 'verified':
                $query->orderByRaw("CASE WHEN is_verified = true THEN 0 ELSE 1 END ASC");
                break;

            case 'possible_business_connection':
                $query->orderByRaw("CASE WHEN employer_name IS NOT NULL AND employer_name != '' THEN 0 ELSE 1 END ASC");
                break;

            case 'possible_employer':
                $query->orderByRaw("
                    CASE WHEN employer_name IS NOT NULL AND employer_name != ''
                         AND (employer_ownership IN ('private', 'parastatal', 'government') OR employer_sector IS NOT NULL)
                    THEN 0 ELSE 1 END ASC
                ");
                break;
        }

        return $presenceJoined;
    }

    private function applyRelevanceSort(Builder $query, ?int $userId, array $myFriendIds, string $q): void
    {
        // Boost friends-of-friends to the top
        if (!empty($myFriendIds)) {
            $safeIds = array_map('intval', $myFriendIds);
            $friendIdList = implode(',', $safeIds);
            $query->orderByRaw("
                CASE WHEN user_profiles.id IN (
                    SELECT CASE WHEN user_id IN ({$friendIdList}) THEN friend_id ELSE user_id END
                    FROM friendships
                    WHERE status = 'accepted'
                      AND (user_id IN ({$friendIdList}) OR friend_id IN ({$friendIdList}))
                      AND user_id != ? AND friend_id != ?
                ) THEN 0 ELSE 1 END ASC
            ", [$userId, $userId]);
        }

        // Text match quality ranking
        if (strlen($q) >= 2) {
            $query->orderByRaw("
                CASE
                    WHEN username ILIKE ? THEN 0
                    WHEN CONCAT(first_name, ' ', last_name) ILIKE ? THEN 1
                    WHEN first_name ILIKE ? OR last_name ILIKE ? THEN 2
                    WHEN username ILIKE ? THEN 3
                    ELSE 4
                END ASC
            ", [$q, "{$q}%", "{$q}%", "{$q}%", "%{$q}%"]);
        }

        $query->orderByDesc('user_profiles.friends_count');
    }

    private function applySortMostMutualFriends(Builder $query, array $myFriendIds): void
    {
        if (empty($myFriendIds)) {
            $query->orderByDesc('user_profiles.friends_count');
            return;
        }

        $safeIds = array_map('intval', $myFriendIds);
        $friendArray = '{' . implode(',', $safeIds) . '}';

        $query->orderByRaw("(
            SELECT COUNT(*) FROM friendships f
            WHERE f.status = 'accepted'
              AND (
                  (f.user_id = user_profiles.id AND f.friend_id = ANY(?))
                  OR
                  (f.friend_id = user_profiles.id AND f.user_id = ANY(?))
              )
        ) DESC", [$friendArray, $friendArray]);
    }

    private function applySortSimilarToMe(Builder $query, ?UserProfile $myProfile): void
    {
        if (!$myProfile) {
            $query->orderByDesc('user_profiles.friends_count');
            return;
        }

        $caseParts = [];
        $params = [];

        if ($myProfile->district_name) {
            $caseParts[] = "CASE WHEN district_name = ? THEN 3 ELSE 0 END";
            $params[] = $myProfile->district_name;
        }
        if ($myProfile->region_name) {
            $caseParts[] = "CASE WHEN region_name = ? THEN 2 ELSE 0 END";
            $params[] = $myProfile->region_name;
        }
        if ($myProfile->employer_name) {
            $caseParts[] = "CASE WHEN LOWER(employer_name) = LOWER(?) THEN 4 ELSE 0 END";
            $params[] = $myProfile->employer_name;
        }
        if ($myProfile->university_name) {
            $caseParts[] = "CASE WHEN LOWER(university_name) = LOWER(?) THEN 4 ELSE 0 END";
            $params[] = $myProfile->university_name;
        }
        if ($myProfile->secondary_school_name) {
            $caseParts[] = "CASE WHEN LOWER(secondary_school_name) = LOWER(?) THEN 3 ELSE 0 END";
            $params[] = $myProfile->secondary_school_name;
        }
        if ($myProfile->primary_school_name) {
            $caseParts[] = "CASE WHEN LOWER(primary_school_name) = LOWER(?) THEN 2 ELSE 0 END";
            $params[] = $myProfile->primary_school_name;
        }
        if ($myProfile->employer_sector) {
            $caseParts[] = "CASE WHEN employer_sector = ? THEN 2 ELSE 0 END";
            $params[] = $myProfile->employer_sector;
        }

        if (!empty($caseParts)) {
            $scoreExpr = implode(' + ', $caseParts);
            $query->orderByRaw("({$scoreExpr}) DESC", $params);
        }

        $query->orderByDesc('user_profiles.friends_count');
    }

    private function applySortSameAreaFirst(Builder $query, ?UserProfile $myProfile): void
    {
        if (!$myProfile) {
            return;
        }

        $params = [];
        $caseExpr = "CASE";

        if ($myProfile->district_name) {
            $caseExpr .= " WHEN district_name = ? THEN 0";
            $params[] = $myProfile->district_name;
        }
        if ($myProfile->region_name) {
            $caseExpr .= " WHEN region_name = ? THEN 1";
            $params[] = $myProfile->region_name;
        }
        $caseExpr .= " ELSE 2 END";

        if (!empty($params)) {
            $query->orderByRaw($caseExpr . " ASC", $params);
        }
    }

    private function applySortMostSharedInterests(Builder $query, ?UserProfile $myProfile): void
    {
        $myInterests = is_array($myProfile?->interests) ? $myProfile->interests : [];

        if (empty($myInterests)) {
            $query->orderByDesc('user_profiles.friends_count');
            return;
        }

        // Build a PostgreSQL array of the viewer's interests (lowercased)
        $pgArray = "ARRAY[" . implode(',', array_map(
            fn($i) => DB::connection()->getPdo()->quote(strtolower($i)),
            $myInterests
        )) . "]::text[]";

        $query->orderByRaw("(
            SELECT COUNT(*) FROM jsonb_array_elements_text(
                COALESCE(user_profiles.interests::jsonb, '[]'::jsonb)
            ) AS elem
            WHERE LOWER(elem) = ANY({$pgArray})
        ) DESC");
    }

    private function applySortLeastGenderedFriends(Builder $query, string $gender): void
    {
        $alias = $gender . '_friends_count';
        $query->orderByRaw("(
            SELECT COUNT(*) FROM friendships f
            JOIN user_profiles up2 ON (
                CASE WHEN f.user_id = user_profiles.id THEN f.friend_id ELSE f.user_id END = up2.id
            )
            WHERE f.status = 'accepted'
              AND (f.user_id = user_profiles.id OR f.friend_id = user_profiles.id)
              AND up2.gender = ?
        ) ASC", [$gender]);
    }

    // ================================================================
    //  Post-query enrichment
    // ================================================================

    private function enrichResults($results, ?int $userId, ?UserProfile $myProfile, array $myFriendIds): array
    {
        $resultIds = collect($results->items())->pluck('id')->toArray();
        if (empty($resultIds)) {
            return [];
        }

        // Batch-load presence
        $presenceMap = UserPresence::whereIn('user_id', $resultIds)
            ->get()
            ->keyBy('user_id');

        // Batch-load friendship status
        $friendshipStatusMap = [];
        if ($userId && !empty($resultIds)) {
            $friendshipStatusMap = $this->batchFriendshipStatus($userId, $resultIds);
        }

        // Compute mutual friends count
        $mutualCountsMap = [];
        if ($userId && !empty($myFriendIds) && !empty($resultIds)) {
            $mutualCountsMap = $this->computeMutualFriendsCounts($resultIds, $myFriendIds);
        }

        return collect($results->items())->map(function (UserProfile $user) use (
            $presenceMap, $mutualCountsMap, $friendshipStatusMap, $myProfile
        ) {
            $presence = $presenceMap[$user->id] ?? null;

            $locationParts = array_filter([
                $user->district_name,
                $user->region_name,
            ]);

            $age = $user->date_of_birth?->age;
            $commonalities = $this->findCommonalities($myProfile, $user);
            $mutualCount = $mutualCountsMap[$user->id] ?? 0;

            // Add mutual friends to in_common if not already represented
            if ($mutualCount > 0) {
                $commonalities[] = $mutualCount === 1
                    ? "1 mutual friend"
                    : "{$mutualCount} mutual friends";
            }

            return [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'gender' => $user->gender,
                'age' => $age,
                'profile_photo_path' => $user->profile_photo_path,
                'cover_photo_path' => $user->cover_photo_path,
                'bio' => $user->bio,
                'region_name' => $user->region_name,
                'district_name' => $user->district_name,
                'location_string' => !empty($locationParts) ? implode(', ', $locationParts) : null,
                'relationship_status' => $user->relationship_status,
                'friends_count' => $user->friends_count ?? 0,
                'posts_count' => $user->posts_count ?? 0,
                'photos_count' => $user->photos_count ?? 0,
                'mutual_friends_count' => $mutualCount,
                'friendship_status' => $friendshipStatusMap[$user->id] ?? 'none',
                'in_common' => $commonalities,
                'is_online' => $presence?->is_online ?? false,
                'is_verified' => $user->is_verified ?? false,
                'primary_school' => $user->primary_school_name,
                'secondary_school' => $user->secondary_school_name,
                'university' => $user->university_name,
                'employer' => $user->employer_name,
                'last_seen_at' => $presence?->last_seen_at?->toIso8601String(),
                'last_active_at' => $user->last_active_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ];
        })->values()->toArray();
    }

    // ================================================================
    //  Social signal helpers (unchanged logic)
    // ================================================================

    private function computeMutualFriendsCounts(array $targetIds, array $myFriendIds): array
    {
        if (empty($targetIds) || empty($myFriendIds)) {
            return [];
        }

        $targetArray = '{' . implode(',', array_map('intval', $targetIds)) . '}';
        $friendArray = '{' . implode(',', array_map('intval', $myFriendIds)) . '}';

        $results = DB::select("
            SELECT target_id, COUNT(*) as mutual_count
            FROM (
                SELECT
                    CASE WHEN user_id = ANY(?) THEN user_id ELSE friend_id END as target_id,
                    CASE WHEN user_id = ANY(?) THEN friend_id ELSE user_id END as friend_of_target
                FROM friendships
                WHERE status = 'accepted'
                  AND (user_id = ANY(?) OR friend_id = ANY(?))
            ) AS target_friends
            WHERE friend_of_target = ANY(?)
            GROUP BY target_id
        ", [$targetArray, $targetArray, $targetArray, $targetArray, $friendArray]);

        $map = [];
        foreach ($results as $row) {
            $map[$row->target_id] = (int) $row->mutual_count;
        }
        return $map;
    }

    private function batchFriendshipStatus(int $userId, array $targetIds): array
    {
        $rows = Friendship::where(function ($q) use ($userId, $targetIds) {
            $q->where('user_id', $userId)->whereIn('friend_id', $targetIds);
        })->orWhere(function ($q) use ($userId, $targetIds) {
            $q->whereIn('user_id', $targetIds)->where('friend_id', $userId);
        })->get();

        $map = [];
        foreach ($rows as $f) {
            $otherId = ($f->user_id === $userId) ? $f->friend_id : $f->user_id;

            if ($f->status === Friendship::STATUS_ACCEPTED) {
                $map[$otherId] = 'friends';
            } elseif ($f->status === Friendship::STATUS_PENDING) {
                $map[$otherId] = ($f->user_id === $userId) ? 'pending_sent' : 'pending_received';
            }
        }
        return $map;
    }

    private function findCommonalities(?UserProfile $me, UserProfile $other): array
    {
        if (!$me) {
            return [];
        }

        $common = [];

        // Same region / district
        if ($me->region_name && $me->region_name === $other->region_name) {
            if ($me->district_name && $me->district_name === $other->district_name) {
                $common[] = "Same district ({$other->district_name})";
            } else {
                $common[] = "Same region ({$other->region_name})";
            }
        }

        // Same employer
        if ($me->employer_name && $other->employer_name
            && strtolower($me->employer_name) === strtolower($other->employer_name)) {
            $common[] = "Same employer ({$other->employer_name})";
        }

        // Same university
        if ($me->university_name && $other->university_name
            && strtolower($me->university_name) === strtolower($other->university_name)) {
            $common[] = "Same university ({$other->university_name})";
        }

        // Same A-level school
        if ($me->alevel_school_name && $other->alevel_school_name
            && strtolower($me->alevel_school_name) === strtolower($other->alevel_school_name)) {
            $common[] = "Same A-level school ({$other->alevel_school_name})";
        }

        // Same secondary school
        if ($me->secondary_school_name && $other->secondary_school_name
            && strtolower($me->secondary_school_name) === strtolower($other->secondary_school_name)) {
            $common[] = "Same secondary school ({$other->secondary_school_name})";
        }

        // Same primary school
        if ($me->primary_school_name && $other->primary_school_name
            && strtolower($me->primary_school_name) === strtolower($other->primary_school_name)) {
            $common[] = "Same primary school ({$other->primary_school_name})";
        }

        // Shared interests
        $myInterests = is_array($me->interests) ? $me->interests : [];
        $otherInterests = is_array($other->interests) ? $other->interests : [];
        if (!empty($myInterests) && !empty($otherInterests)) {
            $shared = array_intersect(
                array_map('strtolower', $myInterests),
                array_map('strtolower', $otherInterests)
            );
            if (!empty($shared)) {
                $count = count($shared);
                $common[] = $count === 1
                    ? "Shared interest: " . ucfirst(array_values($shared)[0])
                    : "{$count} shared interests";
            }
        }

        return $common;
    }
}
