<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopNotificationsController extends Controller
{
    /**
     * GET /shop/users/me/notifications/shop
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'data' => [],
            'meta' => ['note' => 'Reuse global notifications feed; filter client-side for shop topics when available.'],
        ]);
    }
}
