<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Support\Shop\ShopProductFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSocialCommerceController extends Controller
{
    /**
     * GET /shop/social-commerce/feed
     */
    public function feed(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'items' => [],
                'pagination' => ['has_more' => false],
            ],
            'meta' => ['note' => 'Shoppable feed ties to posts; wire when post payloads embed product IDs.'],
        ]);
    }

    /**
     * GET /shop/social-commerce/posts
     */
    public function posts(Request $request): JsonResponse
    {
        return $this->feed($request);
    }

    /**
     * GET /shop/social-commerce/sponsored
     */
    public function sponsored(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * POST /shop/social-commerce/posts/{id}/boost
     */
    public function boost(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Boost requires ads billing integration',
        ], 422);
    }

    /**
     * GET /shop/social-commerce/trending-products
     */
    public function trendingProducts(Request $request): JsonResponse
    {
        $limit = min($request->input('limit', 20), 50);
        $userId = $request->input('user_id');

        $products = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => ShopProductFormatter::product($p, [])),
        ]);
    }
}
