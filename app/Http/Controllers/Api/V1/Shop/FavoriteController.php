<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ProductFavorite;
use App\Support\Shop\ShopProductFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * GET /v1/shop/favorites
     *
     * Flutter treats `data` as a List<Product> plus optional pagination `meta`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $perPage = min($request->input('per_page', 20), 50);

        $favorites = ProductFavorite::where('user_id', $request->user_id)
            ->with(['product' => fn ($q) => $q->with([
                'category:id,name,slug',
                'seller:id,first_name,last_name,username,profile_photo_path,is_verified',
            ])])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $items = $favorites->map(function ($fav) {
            if (! $fav->product) {
                return null;
            }

            return ShopProductFormatter::product($fav->product, [$fav->product_id]);
        })->filter()->values()->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $favorites->currentPage(),
                'per_page' => $favorites->perPage(),
                'total' => $favorites->total(),
                'total_pages' => $favorites->lastPage(),
                'last_page' => $favorites->lastPage(),
                'has_more' => $favorites->hasMorePages(),
            ],
        ]);
    }

    /**
     * POST /v1/shop/products/{id}/favorite (toggle)
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = $request->input('user_id');

        $product = Product::find($id);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $existing = ProductFavorite::where('user_id', $userId)->where('product_id', $id)->first();

        if ($existing) {
            $existing->delete();
            $product->where('id', $id)->where('favorites_count', '>', 0)->decrement('favorites_count');

            return response()->json([
                'success' => true,
                'message' => 'Removed from favorites',
                'data' => ['is_favorited' => false, 'favorites_count' => max(0, $product->fresh()->favorites_count)],
            ]);
        }

        ProductFavorite::create(['user_id' => $userId, 'product_id' => $id]);
        $product->increment('favorites_count');

        return response()->json([
            'success' => true,
            'message' => 'Added to favorites',
            'data' => ['is_favorited' => true, 'favorites_count' => $product->fresh()->favorites_count],
        ]);
    }
}
