<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedUser;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class BlockController extends Controller
{
    /**
     * Block a user.
     */
    public function block(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'blocked_user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (int) $request->user_id;
        $blockedUserId = (int) $request->blocked_user_id;

        if ($userId === $blockedUserId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot block yourself',
            ], 400);
        }

        // Check if already blocked
        if (BlockedUser::isBlocked($userId, $blockedUserId)) {
            return response()->json([
                'success' => true,
                'message' => 'User already blocked',
            ]);
        }

        BlockedUser::create([
            'user_id' => $userId,
            'blocked_user_id' => $blockedUserId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User blocked',
        ]);
    }

    /**
     * Unblock a user.
     */
    public function unblock(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'blocked_user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deleted = BlockedUser::where('user_id', $request->user_id)
            ->where('blocked_user_id', $request->blocked_user_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'User unblocked' : 'User was not blocked',
        ]);
    }

    /**
     * Get list of blocked users.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $blockedUsers = BlockedUser::where('user_id', $userId)
            ->with('blockedUser:id,first_name,last_name,username,profile_photo_path')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'blocked_user_id' => $block->blocked_user_id,
                    'created_at' => $block->created_at,
                    'user' => $block->blockedUser ? [
                        'id' => $block->blockedUser->id,
                        'first_name' => $block->blockedUser->first_name,
                        'last_name' => $block->blockedUser->last_name,
                        'username' => $block->blockedUser->username,
                        'profile_photo_path' => $block->blockedUser->profile_photo_path,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $blockedUsers,
        ]);
    }

    /**
     * Check if a user is blocked.
     */
    public function checkBlocked(Request $request, int $otherUserId): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'is_blocked' => BlockedUser::isBlocked((int) $userId, $otherUserId),
                'is_blocked_by' => BlockedUser::isBlocked($otherUserId, (int) $userId),
            ],
        ]);
    }
}
