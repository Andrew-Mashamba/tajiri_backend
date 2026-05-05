<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSellerPayoutsController extends Controller
{
    /**
     * GET/POST /shop/seller/payouts — seller earnings settle via wallet; listing stub.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'data' => [],
            'meta' => ['note' => 'Seller settlements use Wallet; payouts batch TBD.'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Payout requests not enabled'], 422);
    }

    public function payoutMethods(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function storePayoutMethod(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Configure payout rails in wallet settings'], 422);
    }
}
