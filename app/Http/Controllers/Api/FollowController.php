<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use App\Models\UserFollow;
use App\Models\BlockedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * Follow a user.
     */
    public function follow(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user_profiles,id',
            'follow_id' => 'required|integer|exists:user_profiles,id',
        ]);

        $userId = (int) $request->input('user_id');
        $followId = (int) $request->input('follow_id');

        if ($userId === $followId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot follow yourself',
            ], 422);
        }

        if (BlockedUser::isEitherBlocked($userId, $followId)) {
            return response()->json([
                'success' => false,
                'message' => 'Action not allowed',
            ], 403);
        }

        if (UserFollow::isFollowing($userId, $followId)) {
            return response()->json([
                'success' => false,
                'message' => 'Already following this user',
            ], 409);
        }

        UserFollow::create([
            'follower_id' => $userId,
            'following_id' => $followId,
        ]);

        // Update cached counters
        UserProfile::where('id', $userId)->increment('following_count');
        UserProfile::where('id', $followId)->increment('followers_count');

        // Rebuild interest profile asynchronously after follow
        dispatch(function() use ($userId) {
            \Artisan::call("flywheel:build-interest-profiles", ["--user" => $userId]);
        })->afterResponse();

        return response()->json([
            'success' => true,
            'message' => 'User followed successfully',
        ]);
    }

    /**
     * Unfollow a user.
     */
    public function unfollow(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user_profiles,id',
            'follow_id' => 'required|integer|exists:user_profiles,id',
        ]);

        $userId = (int) $request->input('user_id');
        $followId = (int) $request->input('follow_id');

        $deleted = UserFollow::where('follower_id', $userId)
            ->where('following_id', $followId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'You are not following this user',
            ], 404);
        }

        UserProfile::where('id', $userId)->where('following_count', '>', 0)->decrement('following_count');
        UserProfile::where('id', $followId)->where('followers_count', '>', 0)->decrement('followers_count');

        // Rebuild interest profile asynchronously after unfollow
        dispatch(function() use ($userId) {
            \Artisan::call("flywheel:build-interest-profiles", ["--user" => $userId]);
        })->afterResponse();

        return response()->json([
            'success' => true,
            'message' => 'User unfollowed successfully',
        ]);
    }

    /**
     * Get followers of a user.
     */
    public function followers(int $userId, Request $request): JsonResponse
    {
        $profile = UserProfile::find($userId);
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $currentUserId = $request->input('current_user_id');
        $perPage = min((int) $request->input('per_page', 20), 50);

        $blockedIds = $currentUserId ? BlockedUser::getAllBlockedIds((int) $currentUserId) : [];

        $followers = UserFollow::where('following_id', $userId)
            ->when(!empty($blockedIds), fn($q) => $q->whereNotIn('follower_id', $blockedIds))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $followerProfiles = UserProfile::whereIn('id', $followers->pluck('follower_id'))
            ->get(['id', 'first_name', 'last_name', 'username', 'profile_photo_path'])
            ->keyBy('id');

        $data = $followers->getCollection()->map(function ($follow) use ($followerProfiles, $currentUserId) {
            $profile = $followerProfiles->get($follow->follower_id);
            if (!$profile) return null;

            $item = [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'profile_photo_url' => $profile->profile_photo_url,
                'followed_at' => $follow->created_at?->toIso8601String(),
            ];

            if ($currentUserId) {
                $item['is_following'] = UserFollow::isFollowing((int) $currentUserId, $profile->id);
            }

            return $item;
        })->filter()->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Followers retrieved',
            'meta' => [
                'pagination' => [
                    'current_page' => $followers->currentPage(),
                    'last_page' => $followers->lastPage(),
                    'per_page' => $followers->perPage(),
                    'total' => $followers->total(),
                ],
            ],
        ]);
    }

    /**
     * Get users that a user follows.
     */
    public function following(int $userId, Request $request): JsonResponse
    {
        $profile = UserProfile::find($userId);
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $currentUserId = $request->input('current_user_id');
        $perPage = min((int) $request->input('per_page', 20), 50);

        $blockedIds = $currentUserId ? BlockedUser::getAllBlockedIds((int) $currentUserId) : [];

        $followings = UserFollow::where('follower_id', $userId)
            ->when(!empty($blockedIds), fn($q) => $q->whereNotIn('following_id', $blockedIds))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $followingProfiles = UserProfile::whereIn('id', $followings->pluck('following_id'))
            ->get(['id', 'first_name', 'last_name', 'username', 'profile_photo_path'])
            ->keyBy('id');

        $data = $followings->getCollection()->map(function ($follow) use ($followingProfiles, $currentUserId) {
            $profile = $followingProfiles->get($follow->following_id);
            if (!$profile) return null;

            $item = [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'profile_photo_url' => $profile->profile_photo_url,
                'followed_at' => $follow->created_at?->toIso8601String(),
            ];

            if ($currentUserId) {
                $item['is_following'] = UserFollow::isFollowing((int) $currentUserId, $profile->id);
            }

            return $item;
        })->filter()->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Following list retrieved',
            'meta' => [
                'pagination' => [
                    'current_page' => $followings->currentPage(),
                    'last_page' => $followings->lastPage(),
                    'per_page' => $followings->perPage(),
                    'total' => $followings->total(),
                ],
            ],
        ]);
    }

    /**
     * Check follow status between two users.
     */
    public function checkStatus(int $otherUserId, Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user_profiles,id',
        ]);

        $userId = (int) $request->input('user_id');

        return response()->json([
            'success' => true,
            'data' => [
                'is_following' => UserFollow::isFollowing($userId, $otherUserId),
                'is_followed_by' => UserFollow::isFollowing($otherUserId, $userId),
            ],
        ]);
    }
}
