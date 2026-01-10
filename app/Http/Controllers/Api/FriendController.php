<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FriendController extends Controller
{
    /**
     * Get user's friends list.
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

        // Paginate friends
        $friends = UserProfile::whereIn('id', $friendIds)
            ->select(['id', 'first_name', 'last_name', 'username', 'profile_photo_path', 'bio', 'region_name'])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $friends->items(),
            'meta' => [
                'current_page' => $friends->currentPage(),
                'last_page' => $friends->lastPage(),
                'per_page' => $friends->perPage(),
                'total' => $friends->total(),
            ],
        ]);
    }

    /**
     * Send a friend request.
     */
    public function sendRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'friend_id' => 'required|exists:user_profiles,id|different:user_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;
        $friendId = $request->friend_id;

        // Check if friendship already exists
        $existing = Friendship::getBetween($userId, $friendId);

        if ($existing) {
            if ($existing->status === Friendship::STATUS_ACCEPTED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already friends',
                ], 400);
            }

            if ($existing->status === Friendship::STATUS_PENDING) {
                // If the other person sent request, accept it
                if ($existing->user_id === $friendId) {
                    return $this->acceptRequest($request, $friendId);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Friend request already sent',
                ], 400);
            }

            if ($existing->status === Friendship::STATUS_BLOCKED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send request to this user',
                ], 403);
            }
        }

        // Create friend request
        Friendship::create([
            'user_id' => $userId,
            'friend_id' => $friendId,
            'status' => Friendship::STATUS_PENDING,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Friend request sent',
        ], 201);
    }

    /**
     * Accept a friend request.
     */
    public function acceptRequest(Request $request, int $requesterId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;

        $friendship = Friendship::where('user_id', $requesterId)
            ->where('friend_id', $userId)
            ->where('status', Friendship::STATUS_PENDING)
            ->first();

        if (!$friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            $friendship->accept();

            // Increment friends count for both users
            UserProfile::find($userId)->incrementFriends();
            UserProfile::find($requesterId)->incrementFriends();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Friend request accepted',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Decline a friend request.
     */
    public function declineRequest(Request $request, int $requesterId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;

        $friendship = Friendship::where('user_id', $requesterId)
            ->where('friend_id', $userId)
            ->where('status', Friendship::STATUS_PENDING)
            ->first();

        if (!$friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'success' => true,
            'message' => 'Friend request declined',
        ]);
    }

    /**
     * Cancel a sent friend request.
     */
    public function cancelRequest(Request $request, int $friendId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;

        $friendship = Friendship::where('user_id', $userId)
            ->where('friend_id', $friendId)
            ->where('status', Friendship::STATUS_PENDING)
            ->first();

        if (!$friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'success' => true,
            'message' => 'Friend request cancelled',
        ]);
    }

    /**
     * Remove a friend.
     */
    public function removeFriend(Request $request, int $friendId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;

        $friendship = Friendship::getBetween($userId, $friendId);

        if (!$friendship || $friendship->status !== Friendship::STATUS_ACCEPTED) {
            return response()->json([
                'success' => false,
                'message' => 'Friendship not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            $friendship->delete();

            // Decrement friends count for both users
            UserProfile::find($userId)->decrementFriends();
            UserProfile::find($friendId)->decrementFriends();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Friend removed',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove friend: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pending friend requests.
     */
    public function getRequests(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        // Received requests
        $received = Friendship::where('friend_id', $userId)
            ->where('status', Friendship::STATUS_PENDING)
            ->with('user:id,first_name,last_name,username,profile_photo_path,bio')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'type' => 'received',
                    'user' => $f->user,
                    'created_at' => $f->created_at,
                ];
            });

        // Sent requests
        $sent = Friendship::where('user_id', $userId)
            ->where('status', Friendship::STATUS_PENDING)
            ->with('friend:id,first_name,last_name,username,profile_photo_path,bio')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'type' => 'sent',
                    'user' => $f->friend,
                    'created_at' => $f->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'received' => $received,
                'sent' => $sent,
            ],
        ]);
    }

    /**
     * Get friend suggestions based on mutual friends, location, school, etc.
     */
    public function getSuggestions(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $limit = $request->query('limit', 20);

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

        // Get existing friend IDs and pending request IDs
        $friendIds = $user->getFriendIds();
        $pendingIds = Friendship::where(function ($q) use ($userId) {
            $q->where('user_id', $userId)->orWhere('friend_id', $userId);
        })->where('status', Friendship::STATUS_PENDING)
            ->get()
            ->flatMap(function ($f) use ($userId) {
                return [$f->user_id, $f->friend_id];
            })
            ->filter(fn($id) => $id != $userId)
            ->unique()
            ->toArray();

        $excludeIds = array_merge($friendIds, $pendingIds, [$userId]);

        // Build suggestions query
        $query = UserProfile::whereNotIn('id', $excludeIds)
            ->select(['id', 'first_name', 'last_name', 'username', 'profile_photo_path', 'bio', 'region_name', 'primary_school_name', 'secondary_school_name', 'university_name']);

        // Priority: Same region, same school, same university
        $suggestions = $query->orderByRaw("
            CASE
                WHEN region_id = ? THEN 1
                WHEN primary_school_id = ? AND primary_school_id IS NOT NULL THEN 2
                WHEN secondary_school_id = ? AND secondary_school_id IS NOT NULL THEN 3
                WHEN university_id = ? AND university_id IS NOT NULL THEN 4
                ELSE 5
            END
        ", [$user->region_id, $user->primary_school_id, $user->secondary_school_id, $user->university_id])
            ->limit($limit)
            ->get();

        // Add mutual friends count
        $suggestions->each(function ($suggestion) use ($friendIds) {
            $suggestionFriendIds = $suggestion->getFriendIds();
            $suggestion->mutual_friends_count = count(array_intersect($friendIds, $suggestionFriendIds));
        });

        return response()->json([
            'success' => true,
            'data' => $suggestions,
        ]);
    }

    /**
     * Get mutual friends between two users.
     */
    public function getMutualFriends(Request $request, int $otherUserId): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $user1 = UserProfile::find($userId);
        $user2 = UserProfile::find($otherUserId);

        if (!$user1 || !$user2) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user1FriendIds = $user1->getFriendIds();
        $user2FriendIds = $user2->getFriendIds();

        $mutualIds = array_intersect($user1FriendIds, $user2FriendIds);

        $mutualFriends = UserProfile::whereIn('id', $mutualIds)
            ->select(['id', 'first_name', 'last_name', 'username', 'profile_photo_path'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $mutualFriends,
        ]);
    }

    /**
     * Check friendship status with another user.
     */
    public function checkStatus(Request $request, int $otherUserId): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $friendship = Friendship::getBetween($userId, $otherUserId);

        if (!$friendship) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'none',
                    'can_send_request' => true,
                ],
            ]);
        }

        $status = $friendship->status;
        $isRequester = $friendship->user_id == $userId;

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $status,
                'is_requester' => $isRequester,
                'can_send_request' => false,
                'can_accept' => $status === Friendship::STATUS_PENDING && !$isRequester,
                'can_cancel' => $status === Friendship::STATUS_PENDING && $isRequester,
            ],
        ]);
    }

    /**
     * Search users.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $query = $request->query('q');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$query || strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
            ], 400);
        }

        $users = UserProfile::where(function ($q) use ($query) {
            $q->where('first_name', 'ILIKE', "%{$query}%")
                ->orWhere('last_name', 'ILIKE', "%{$query}%")
                ->orWhere('username', 'ILIKE', "%{$query}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$query}%"]);
        })
            ->select(['id', 'first_name', 'last_name', 'username', 'profile_photo_path', 'bio', 'region_name'])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
