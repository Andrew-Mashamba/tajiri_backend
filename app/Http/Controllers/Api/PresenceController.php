<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Models\UserPresence;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PresenceController extends Controller
{
    /**
     * Update user presence (heartbeat).
     */
    public function heartbeat(Request $request): JsonResponse
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

        UserPresence::heartbeat((int) $request->user_id);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Mark user as offline.
     */
    public function offline(Request $request): JsonResponse
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

        UserPresence::goOffline((int) $request->user_id);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Store or update FCM token.
     *
     * Accepts either `token` or `fcm_token` for the FCM device token.
     * `device_id` is optional — defaults to 'default' for single-device users.
     */
    public function storeFcmToken(Request $request): JsonResponse
    {
        // Accept both `token` (app sends this) and `fcm_token`
        $tokenValue = $request->input('token') ?? $request->input('fcm_token');

        if (!$tokenValue) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => ['token' => ['The token field is required.']],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'device_id' => 'nullable|string|max:255',
            'platform' => 'nullable|in:ios,android,web',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deviceId = $request->input('device_id', 'default');

        FcmToken::storeToken(
            (int) $request->user_id,
            $deviceId,
            $tokenValue,
            $request->platform
        );

        return response()->json([
            'success' => true,
            'message' => 'FCM token stored',
        ]);
    }

    /**
     * Remove FCM token.
     *
     * `device_id` is optional — defaults to 'default'.
     */
    public function removeFcmToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'device_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deviceId = $request->input('device_id', 'default');
        FcmToken::removeToken((int) $request->user_id, $deviceId);

        return response()->json([
            'success' => true,
            'message' => 'FCM token removed',
        ]);
    }
}
