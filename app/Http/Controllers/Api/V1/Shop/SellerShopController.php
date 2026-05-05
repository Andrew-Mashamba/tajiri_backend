<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\SellerShopProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerShopController extends Controller
{
    /**
     * GET /shop/seller/shop?user_id= — public storefront profile.
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $profile = SellerShopProfile::where('user_id', $request->integer('user_id'))->first();

        return response()->json([
            'success' => true,
            'data' => $profile ? $profile->toArray() : [
                'user_id' => $request->integer('user_id'),
                'store_name' => null,
                'headline' => null,
                'description' => null,
                'banner_image_url' => null,
                'logo_url' => null,
                'accent_hex' => null,
                'social_links' => null,
                'settings' => null,
            ],
        ]);
    }

    /**
     * PUT /shop/seller/shop or PUT /shop/shops/{userId}
     */
    public function update(Request $request, ?int $userId = null): JsonResponse
    {
        $ownerId = $userId ?? $request->integer('user_id');
        $request->merge(['user_id' => $ownerId]);

        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'store_name' => 'nullable|string|max:255',
            'headline' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:8000',
            'banner_image_url' => 'nullable|string|max:2000',
            'logo_url' => 'nullable|string|max:2000',
            'accent_hex' => 'nullable|string|max:16',
            'social_links' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $profile = SellerShopProfile::firstOrNew(['user_id' => $ownerId]);
        $profile->fill($request->only([
            'store_name',
            'headline',
            'description',
            'banner_image_url',
            'logo_url',
            'accent_hex',
            'social_links',
            'settings',
        ]));
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Shop profile saved',
            'data' => $profile->toArray(),
        ]);
    }
}
