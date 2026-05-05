<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPaymentsController extends Controller
{
    /**
     * GET /shop/payments/methods
     */
    public function methods(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $wallet = Wallet::getOrCreate($request->integer('user_id'));

        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => 'wallet',
                    'type' => 'wallet',
                    'label' => 'TAJIRI Wallet',
                    'currency' => $wallet->currency,
                    'balance' => (float) $wallet->balance,
                ],
            ],
        ]);
    }

    /**
     * POST /shop/payments/methods
     */
    public function storeMethod(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Add payment methods via main wallet / card flows when enabled.',
        ], 422);
    }

    /**
     * GET /shop/payments/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'meta' => ['note' => 'Use GET /wallet/{userId}/transactions for full ledger.'],
            'data' => [],
        ]);
    }

    public function intent(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Payment intents use wallet checkout'], 422);
    }

    public function capture(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not implemented'], 422);
    }

    public function webhook(Request $request, string $provider): JsonResponse
    {
        return response()->json(['success' => true, 'provider' => $provider]);
    }

    public function mobileMoney(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Use checkout mpesa_phone + wallet pairing when backend enables it'], 422);
    }
}
