<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSupportController extends Controller
{
    /**
     * GET /shop/support/tickets
     */
    public function tickets(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'data' => [],
            'meta' => ['note' => 'Customer support tickets integration TBD.'],
        ]);
    }
}
