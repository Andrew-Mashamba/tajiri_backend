<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeedController extends Controller
{
    /**
     * Get personalized feed for a user.
     * Includes posts from friends and public posts, ranked by interest profile.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $user = UserProfile::find($userId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Cache personalized feed for 5 minutes per user
        $cacheKey = "feed_personalized_{$userId}_page_{$page}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        // Get friend IDs
        $friendIds = $user->getFriendIds();
        $friendIds[] = $userId; // Include own posts

        $posts = Post::where(function ($query) use ($friendIds, $userId) {
            // Posts from user and friends (all privacy levels)
            $query->whereIn('user_id', $friendIds)
                ->where(function ($q) use ($userId, $friendIds) {
                    $q->where('privacy', Post::PRIVACY_PUBLIC)
                        ->orWhere('privacy', Post::PRIVACY_FRIENDS)
                        ->orWhere('user_id', $userId);
                });
        })
            ->orWhere(function ($query) use ($friendIds) {
                // Public posts from non-friends (discovery)
                $query->whereNotIn('user_id', $friendIds)
                    ->where('privacy', Post::PRIVACY_PUBLIC);
            })
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'media',
                'originalPost.user:id,first_name,last_name,username,profile_photo_path',
                'originalPost.media',
            ])
            ->withCount(['comments', 'likes'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add is_liked flag for each post
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $post->is_liked = $post->isLikedBy($userId);
            return $post;
        });

        // Personalized re-ranking using interest profile
        $profile = DB::table('user_interest_profiles')->where('user_id', $userId)->first();
        if ($profile) {
            $topCreators = json_decode($profile->top_creators ?? '[]', true) ?: [];
            $topHashtags = json_decode($profile->top_hashtags ?? '[]', true) ?: [];
            $preferredFormats = json_decode($profile->preferred_formats ?? '[]', true) ?: [];

            $posts->getCollection()->transform(function ($post) use ($topCreators, $topHashtags, $preferredFormats, $userId) {
                $score = 0;

                // Creator affinity boost
                if (in_array($post->user_id, $topCreators)) {
                    $score += 30;
                }

                // Engagement signals
                $score += min($post->likes_count * 0.5, 20);
                $score += min($post->comments_count * 1.0, 20);

                // Recency boost (decay over 48h)
                $hoursAgo = now()->diffInHours($post->created_at);
                $score += max(0, 30 - ($hoursAgo * 0.625)); // Full 30 points if <1h, 0 at 48h

                // Thread boost (gossip thread posts get extra visibility)
                if ($post->thread_id) {
                    $score += 15;
                }

                // Collaboration boost: posts tagging multiple users get +20 score
                if (isset($post->tagged_users_count) && $post->tagged_users_count > 1) {
                    $score += 20;
                }

                $post->feed_score = $score;
                return $post;
            });

            // Re-sort by feed score
            $sorted = $posts->getCollection()->sortByDesc('feed_score')->values();
            $posts->setCollection($sorted);
        }

        // Enrich post users with is_following and mutual follower data
        $postItems = $posts->getCollection();
        if ($userId && $postItems->isNotEmpty()) {
            $authorIds = $postItems->pluck("user_id")->unique()->filter(fn($id) => $id != $userId)->values()->toArray();
            if (!empty($authorIds)) {
                $followingIds = \App\Models\UserFollow::where("follower_id", $userId)->whereIn("following_id", $authorIds)->pluck("following_id")->toArray();
                $myFollowingIds = \App\Models\UserFollow::where("follower_id", $userId)->pluck("following_id")->toArray();
                $mutualData = [];
                if (!empty($myFollowingIds)) {
                    foreach ($authorIds as $authorId) {
                        $mutualFollowerIds = \App\Models\UserFollow::where("following_id", $authorId)->whereIn("follower_id", $myFollowingIds)->limit(3)->pluck("follower_id")->toArray();
                        if (!empty($mutualFollowerIds)) {
                            $names = \App\Models\UserProfile::whereIn("id", array_slice($mutualFollowerIds, 0, 2))->pluck("username")->toArray();
                            $totalCount = \App\Models\UserFollow::where("following_id", $authorId)->whereIn("follower_id", $myFollowingIds)->count();
                            $mutualData[$authorId] = ["names" => $names, "count" => $totalCount];
                        }
                    }
                }
                foreach ($postItems as $post) {
                    if ($post->user) {
                        $post->user->is_following = in_array($post->user_id, $followingIds);
                        $mutual = $mutualData[$post->user_id] ?? null;
                        $post->user->mutual_followers_count = $mutual ? $mutual["count"] : 0;
                        $post->user->mutual_follower_names = $mutual ? $mutual["names"] : [];
                    }
                }
            }
        }

        $responseData = [
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ];

        // Cache the response for 5 minutes
        Cache::put($cacheKey, $responseData, 300);

        return response()->json($responseData);
    }

    /**
     * Get feed with only friends posts.
     */
    public function friendsFeed(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $user = UserProfile::find($userId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Get friend IDs (excluding self for friends-only feed)
        $friendIds = $user->getFriendIds();

        if (empty($friendIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
                'message' => 'No friends yet. Add friends to see their posts.',
            ]);
        }

        $posts = Post::whereIn('user_id', $friendIds)
            ->whereIn('privacy', [Post::PRIVACY_PUBLIC, Post::PRIVACY_FRIENDS])
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'media',
                'originalPost.user:id,first_name,last_name,username,profile_photo_path',
                'originalPost.media',
            ])
            ->withCount(['comments', 'likes'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add is_liked flag
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $post->is_liked = $post->isLikedBy($userId);
            return $post;
        });

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get discovery feed (public posts from non-friends).
     */
    public function discover(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $excludeIds = [$userId];

        if ($userId) {
            $user = UserProfile::find($userId);
            if ($user) {
                $excludeIds = array_merge($excludeIds, $user->getFriendIds());
            }
        }

        $posts = Post::whereNotIn('user_id', $excludeIds)
            ->where('privacy', Post::PRIVACY_PUBLIC)
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path,region_name',
                'media',
            ])
            ->withCount(['comments', 'likes'])
            ->orderBy('likes_count', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add is_liked flag if user provided
        if ($userId) {
            $posts->getCollection()->transform(function ($post) use ($userId) {
                $post->is_liked = $post->isLikedBy($userId);
                return $post;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get trending posts (most liked/commented in recent period).
     */
    public function trending(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $userId = $request->query('user_id');

        $posts = Post::where('privacy', Post::PRIVACY_PUBLIC)
            ->where('created_at', '>=', now()->subDays(7))
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path',
                'media',
            ])
            ->withCount(['comments', 'likes'])
            ->orderByRaw('(likes_count + comments_count * 2) DESC')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($userId) {
            $posts->getCollection()->transform(function ($post) use ($userId) {
                $post->is_liked = $post->isLikedBy($userId);
                return $post;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get posts by location (same region).
     */
    public function nearby(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $regionId = $request->query('region_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$regionId && $userId) {
            $user = UserProfile::find($userId);
            $regionId = $user?->region_id;
        }

        if (!$regionId) {
            return response()->json([
                'success' => false,
                'message' => 'Region ID is required',
            ], 400);
        }

        // Get users in the same region
        $userIds = UserProfile::where('region_id', $regionId)
            ->pluck('id')
            ->toArray();

        $posts = Post::whereIn('user_id', $userIds)
            ->where('privacy', Post::PRIVACY_PUBLIC)
            ->with([
                'user:id,first_name,last_name,username,profile_photo_path,region_name',
                'media',
            ])
            ->withCount(['comments', 'likes'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($userId) {
            $posts->getCollection()->transform(function ($post) use ($userId) {
                $post->is_liked = $post->isLikedBy($userId);
                return $post;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }
}
