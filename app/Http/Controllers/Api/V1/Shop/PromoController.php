<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Support\Shop\ShopPromo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    /**
     * POST /shop/promo/validate
     *
     * Flutter expects: { success, discount, description?, message? } with discount at root.
     */
    public function validateCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:64',
            'user_id' => 'required|integer',
        ]);

        $code = strtoupper(trim($request->input('code')));
        $promos = config('shop.promo_codes', []);
        if ($code === '' || ! isset($promos[$code])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired promo code',
                'discount' => 0,
            ], 422);
        }

        $rule = $promos[$code];
        $description = $rule['description'] ?? null;
        $discountPreview = 0.0;
        if (($rule['type'] ?? '') === 'fixed') {
            $discountPreview = (float) ($rule['amount'] ?? 0);
        }
        // percent-type: actual TZS computed at checkout from subtotal; preview stays 0

        return response()->json([
            'success' => true,
            'discount' => $discountPreview,
            'description' => $description,
        ]);
    }

    /**
     * POST /shop/coupons/apply — same rules as promo; returns discount against subtotal.
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:64',
            'user_id' => 'required|integer',
            'subtotal' => 'nullable|numeric|min:0',
        ]);

        $subtotal = (float) $request->input('subtotal', 0);
        $pr = ShopPromo::compute($request->string('code')->toString(), $subtotal);

        if (! $pr['valid']) {
            return response()->json([
                'success' => false,
                'message' => $pr['message'] ?? 'Invalid coupon',
                'discount' => 0,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'code' => strtoupper(trim($request->string('code')->toString())),
            'discount' => $pr['discount'],
            'description' => $pr['description'],
        ]);
    }
}
