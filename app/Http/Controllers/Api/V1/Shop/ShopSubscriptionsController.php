<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSubscriptionsController extends Controller
{
    /**
     * GET /shop/subscriptions/plans
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => config('shop.subscription_plans', []),
        ]);
    }

    /**
     * POST /shop/subscriptions/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'plan_id' => 'required|string|max:64',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription checkout not billed yet; use wallet when productized.',
            'data' => [
                'plan_id' => $request->string('plan_id')->toString(),
                'checkout_url' => null,
            ],
        ]);
    }

    /**
     * GET /shop/subscriptions/me
     */
    public function me(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'data' => [
                'active_plan_id' => null,
                'renews_at' => null,
            ],
        ]);
    }
}
