<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingQuoteController extends Controller
{
    /**
     * POST /shop/shipping/quote — MVP flat fee from config + per-item hints.
     */
    public function quote(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'items' => 'nullable|array',
            'destination' => 'nullable|string|max:500',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $base = (float) config('shop.shipping.base_fee_tzs', 3500);
        $perKg = (float) config('shop.shipping.per_kg_tzs', 2000);

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => 'TZS',
                'amount_tzs' => round($base, 2),
                'breakdown' => [
                    'base_fee_tzs' => $base,
                    'estimated_weight_charge_tzs' => 0,
                    'per_kg_reference_tzs' => $perKg,
                ],
                'notes' => 'Estimated quote; final delivery fee may follow seller product settings.',
            ],
        ]);
    }

    /**
     * POST /shop/checkout/shipping-quote — alias for checkout flow.
     */
    public function checkoutQuote(Request $request): JsonResponse
    {
        return $this->quote($request);
    }
}
