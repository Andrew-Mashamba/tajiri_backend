<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class FeedController extends Controller
{
    /**
     * Get personalized feed for a user.
     * Includes posts from friends and public posts.
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
     * Get feed with only friends' posts.
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
