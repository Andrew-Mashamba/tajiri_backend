<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ProductCategory;
use App\Models\Shop\ProductFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * GET /v1/shop/categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = ProductCategory::active()
            ->roots()
            ->with(['children' => fn($q) => $q->active()->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * GET /v1/shop/categories/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $category = ProductCategory::with(['children' => fn($q) => $q->active()->orderBy('sort_order'), 'parent:id,name,slug'])
            ->find($id);

        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    /**
     * GET /v1/shop/categories/{id}/products
     */
    public function products(Request $request, int $id): JsonResponse
    {
        $category = ProductCategory::find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        $perPage = min($request->input('per_page', 20), 50);
        $userId = $request->input('user_id');

        // Include products from child categories
        $categoryIds = ProductCategory::where('parent_id', $id)->pluck('id')->push($id);

        $query = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->whereIn('category_id', $categoryIds);

        // Sorting (match ProductController / Flutter)
        $query = match ($request->input('sort_by', 'newest')) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'price_low', 'price_asc' => $query->orderBy('price', 'asc'),
            'price_high', 'price_desc' => $query->orderBy('price', 'desc'),
            'popular' => $query->orderBy('orders_count', 'desc'),
            'rating' => $query->orderBy('rating', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $products = $query->paginate($perPage);

        $favoriteIds = $userId ? ProductFavorite::where('user_id', $userId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray() : [];

        $items = $products->map(function ($product) use ($favoriteIds) {
            $data = $product->toArray();
            $seller = $product->seller;
            $data['seller'] = $seller ? [
                'id' => $seller->id,
                'name' => trim($seller->first_name . ' ' . $seller->last_name),
                'username' => $seller->username,
                'avatar_url' => $seller->profile_photo_path,
                'is_verified' => (bool) ($seller->is_verified ?? false),
            ] : null;
            $data['is_favorited'] = in_array($product->id, $favoriteIds);
            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
                'items' => $items,
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'total_pages' => $products->lastPage(),
                    'has_more' => $products->hasMorePages(),
                ],
            ],
        ]);
    }
}
