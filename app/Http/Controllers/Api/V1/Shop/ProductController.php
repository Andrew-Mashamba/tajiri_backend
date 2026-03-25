<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ProductCategory;
use App\Models\Shop\ProductFavorite;
use App\Models\BlockedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * GET /v1/shop/products
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
            'category_id' => 'integer|exists:product_categories,id',
            'search' => 'string|max:255',
            'sort_by' => 'string|in:newest,oldest,price_low,price_high,popular,rating',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:0',
            'condition' => 'string|in:new,used,refurbished',
            'type' => 'string|in:physical,digital,service',
            'seller_id' => 'integer',
        ]);

        $perPage = min($request->input('per_page', 20), 50);
        $userId = $request->input('user_id');

        $query = Product::active()->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified']);

        // Exclude blocked users
        if ($userId) {
            $blockedIds = BlockedUser::where('user_id', $userId)->pluck('blocked_user_id')
                ->merge(BlockedUser::where('blocked_user_id', $userId)->pluck('user_id'))
                ->unique()->toArray();
            if (!empty($blockedIds)) {
                $query->whereNotIn('user_id', $blockedIds);
            }
        }

        // Filters
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('min_price') || $request->filled('max_price')) {
            $query->inPriceRange($request->input('min_price'), $request->input('max_price'));
        }
        if ($request->filled('condition')) {
            $query->byCondition($request->condition);
        }
        if ($request->filled('type')) {
            $query->byType($request->type);
        }
        if ($request->filled('seller_id')) {
            $query->where('user_id', $request->seller_id);
        }

        // Sorting
        $query = match ($request->input('sort_by', 'newest')) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'price_low' => $query->orderBy('price', 'asc'),
            'price_high' => $query->orderBy('price', 'desc'),
            'popular' => $query->orderBy('orders_count', 'desc'),
            'rating' => $query->orderBy('rating', 'desc')->orderBy('reviews_count', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $products = $query->paginate($perPage);

        // Attach is_favorited flag
        $favoriteIds = [];
        if ($userId) {
            $favoriteIds = ProductFavorite::where('user_id', $userId)
                ->whereIn('product_id', $products->pluck('id'))
                ->pluck('product_id')
                ->toArray();
        }

        $items = $products->map(fn($p) => $this->formatProduct($p, $favoriteIds));

        return response()->json([
            'success' => true,
            'data' => [
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

    /**
     * GET /v1/shop/products/featured
     */
    public function featured(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        $limit = min($request->input('limit', 20), 50);

        $products = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->where('reviews_count', '>', 0)
            ->orderByRaw('(rating * reviews_count + orders_count * 2) DESC')
            ->limit($limit)
            ->get();

        $favoriteIds = $userId ? ProductFavorite::where('user_id', $userId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray() : [];

        return response()->json([
            'success' => true,
            'data' => $products->map(fn($p) => $this->formatProduct($p, $favoriteIds)),
        ]);
    }

    /**
     * GET /v1/shop/products/trending
     */
    public function trending(Request $request): JsonResponse
    {
        $userId = $request->input('user_id');
        $limit = min($request->input('limit', 20), 50);

        $products = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByRaw('(views_count + favorites_count * 2 + orders_count * 5) DESC')
            ->limit($limit)
            ->get();

        $favoriteIds = $userId ? ProductFavorite::where('user_id', $userId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray() : [];

        return response()->json([
            'success' => true,
            'data' => $products->map(fn($p) => $this->formatProduct($p, $favoriteIds)),
        ]);
    }

    /**
     * GET /v1/shop/products/recommended
     */
    public function recommended(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = $request->input('user_id');
        $limit = min($request->input('limit', 20), 50);

        // Recommend based on: user's favorited categories, purchased categories, and popular items
        $favoritedCategoryIds = DB::table('product_favorites')
            ->join('products', 'products.id', '=', 'product_favorites.product_id')
            ->where('product_favorites.user_id', $userId)
            ->whereNotNull('products.category_id')
            ->pluck('products.category_id')
            ->unique();

        $purchasedCategoryIds = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->where('orders.buyer_id', $userId)
            ->whereNotNull('products.category_id')
            ->pluck('products.category_id')
            ->unique();

        $preferredCategories = $favoritedCategoryIds->merge($purchasedCategoryIds)->unique();

        $query = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->where('user_id', '!=', $userId);

        // Exclude blocked users
        $blockedIds = BlockedUser::where('user_id', $userId)->pluck('blocked_user_id')
            ->merge(BlockedUser::where('blocked_user_id', $userId)->pluck('user_id'))
            ->unique()->toArray();
        if (!empty($blockedIds)) {
            $query->whereNotIn('user_id', $blockedIds);
        }

        if ($preferredCategories->isNotEmpty()) {
            $query->orderByRaw('CASE WHEN category_id IN (' . $preferredCategories->implode(',') . ') THEN 0 ELSE 1 END');
        }

        $products = $query->orderByRaw('(rating * reviews_count + orders_count * 2 + views_count * 0.1) DESC')
            ->limit($limit)
            ->get();

        $favoriteIds = ProductFavorite::where('user_id', $userId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray();

        return response()->json([
            'success' => true,
            'data' => $products->map(fn($p) => $this->formatProduct($p, $favoriteIds)),
        ]);
    }

    /**
     * GET /v1/shop/products/nearby
     */
    public function nearby(Request $request): JsonResponse
    {
        $request->validate([
            'near_lat' => 'required|numeric|between:-90,90',
            'near_lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'integer|min:1|max:100',
        ]);

        $userId = $request->input('user_id');
        $radiusKm = $request->input('radius_km', 10);
        $limit = min($request->input('limit', 20), 50);

        $products = Product::active()
            ->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified'])
            ->nearby($request->near_lat, $request->near_lng, $radiusKm)
            ->limit($limit)
            ->get();

        $favoriteIds = $userId ? ProductFavorite::where('user_id', $userId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray() : [];

        return response()->json([
            'success' => true,
            'data' => $products->map(fn($p) => $this->formatProduct($p, $favoriteIds)),
        ]);
    }

    /**
     * GET /v1/shop/products/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->input('user_id');

        $product = Product::with([
            'category:id,name,slug',
            'seller:id,first_name,last_name,username,profile_photo_path,is_verified',
        ])->find($id);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Check if deleted/archived (only owner can see)
        if (in_array($product->status, ['archived']) && (!$userId || !$product->isOwnedBy($userId))) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $isFavorited = $userId ? ProductFavorite::where('user_id', $userId)->where('product_id', $id)->exists() : false;

        // Seller stats
        $sellerData = null;
        if ($product->seller) {
            $sellerData = [
                'id' => $product->seller->id,
                'name' => trim($product->seller->first_name . ' ' . $product->seller->last_name),
                'username' => $product->seller->username,
                'avatar_url' => $product->seller->profile_photo_path,
                'is_verified' => (bool) ($product->seller->is_verified ?? false),
                'rating' => round(Product::where('user_id', $product->user_id)->where('reviews_count', '>', 0)->avg('rating') ?? 0, 2),
                'products_count' => Product::where('user_id', $product->user_id)->active()->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($product->toArray(), [
                'seller' => $sellerData,
                'is_favorited' => $isFavorited,
            ]),
        ]);
    }

    /**
     * POST /v1/shop/products
     */
    public function store(Request $request): JsonResponse
    {
        // Accept seller_id as alias for user_id
        if (!$request->has('user_id') && $request->has('seller_id')) {
            $request->merge(['user_id' => $request->input('seller_id')]);
        }

        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'type' => 'required|string|in:physical,digital,service',
            'price' => 'required|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0|gt:price',
            'stock_quantity' => 'integer|min:0',
            'category_id' => 'nullable|integer|exists:product_categories,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'condition' => 'nullable|string|in:new,used,refurbished',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'allow_pickup' => 'boolean',
            'allow_delivery' => 'boolean',
            'allow_shipping' => 'boolean',
            'delivery_fee' => 'nullable|numeric|min:0',
            'delivery_notes' => 'nullable|string|max:1000',
            'download_url' => 'nullable|url|max:500',
            'duration_minutes' => 'nullable|integer|min:1',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'status' => 'string|in:draft,active',
        ]);

        $product = Product::create(array_merge(
            $request->only([
                'user_id', 'title', 'description', 'type', 'price', 'compare_at_price',
                'category_id', 'tags', 'condition', 'location_name', 'latitude', 'longitude',
                'allow_pickup', 'allow_delivery', 'allow_shipping', 'delivery_fee', 'delivery_notes',
                'download_url', 'duration_minutes',
            ]),
            [
                'stock_quantity' => $request->input('stock_quantity', $request->type === 'digital' ? 999 : 1),
                'status' => $request->input('status', 'draft'),
                'currency' => 'TZS',
            ]
        ));

        // Handle image uploads
        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $path = $image->store('products/' . $product->id, 'public');
                $imagePaths[] = Storage::disk('public')->url($path);
            }
            $product->update([
                'images' => $imagePaths,
                'thumbnail_url' => $imagePaths[0],
            ]);
        }

        // Update category product count
        if ($product->category_id && $product->status === 'active') {
            ProductCategory::where('id', $product->category_id)->increment('product_count');
        }

        $product->load(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified']);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => ['product' => $product],
        ], 201);
    }

    /**
     * PUT /v1/shop/products/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'title' => 'string|max:255',
            'description' => 'string|max:5000',
            'price' => 'numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'category_id' => 'nullable|integer|exists:product_categories,id',
            'tags' => 'nullable|array',
            'condition' => 'nullable|string|in:new,used,refurbished',
            'status' => 'string|in:draft,active,archived',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'allow_pickup' => 'boolean',
            'allow_delivery' => 'boolean',
            'allow_shipping' => 'boolean',
            'delivery_fee' => 'nullable|numeric|min:0',
            'delivery_notes' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        if (!$product->isOwnedBy($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $oldStatus = $product->status;
        $oldCategoryId = $product->category_id;

        $product->update($request->only([
            'title', 'description', 'price', 'compare_at_price', 'stock_quantity',
            'category_id', 'tags', 'condition', 'status', 'location_name', 'latitude', 'longitude',
            'allow_pickup', 'allow_delivery', 'allow_shipping', 'delivery_fee', 'delivery_notes',
        ]));

        // Handle image uploads
        if ($request->hasFile('images')) {
            // Delete old images from storage
            if ($product->images) {
                foreach ($product->images as $oldUrl) {
                    $oldPath = str_replace(Storage::disk('public')->url(''), '', $oldUrl);
                    if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            }

            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $path = $image->store('products/' . $product->id, 'public');
                $imagePaths[] = Storage::disk('public')->url($path);
            }
            $product->update([
                'images' => $imagePaths,
                'thumbnail_url' => $imagePaths[0],
            ]);
        }

        // Update category counts if category or status changed
        if ($oldCategoryId !== $product->category_id || $oldStatus !== $product->status) {
            if ($oldCategoryId && $oldStatus === 'active') {
                ProductCategory::where('id', $oldCategoryId)->where('product_count', '>', 0)->decrement('product_count');
            }
            if ($product->category_id && $product->status === 'active') {
                ProductCategory::where('id', $product->category_id)->increment('product_count');
            }
        }

        // Auto sold_out check
        if ($product->stock_quantity <= 0 && $product->status === 'active') {
            $product->update(['status' => 'sold_out']);
        }

        $product->load(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified']);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => ['product' => $product],
        ]);
    }

    /**
     * DELETE /v1/shop/products/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        if (!$product->isOwnedBy($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Update category count
        if ($product->category_id && $product->status === 'active') {
            ProductCategory::where('id', $product->category_id)->where('product_count', '>', 0)->decrement('product_count');
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * POST /v1/shop/products/{id}/view
     */
    public function incrementView(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $product->increment('views_count');

        return response()->json(['success' => true, 'message' => 'View recorded']);
    }

    /**
     * GET /shop/products/seller?user_id=  OR  GET /shop/sellers/{userId}/products
     */
    public function sellerProducts(Request $request, ?int $userId = null): JsonResponse
    {
        $userId = $userId ?? (int) $request->input('user_id') ?: (int) $request->input('seller_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id or seller_id is required'], 422);
        }

        $perPage = min($request->input('per_page', 20), 50);
        $currentUserId = $request->input('user_id');

        $query = Product::where('user_id', $userId)
            ->with(['category:id,name,slug']);

        // Non-owners only see active products
        if (!$currentUserId || $currentUserId != $userId) {
            $query->active();
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $favoriteIds = $currentUserId ? ProductFavorite::where('user_id', $currentUserId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray() : [];

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $products->map(fn($p) => $this->formatProduct($p, $favoriteIds)),
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

    /**
     * Format product for API response
     */
    private function formatProduct(Product $product, array $favoriteIds = []): array
    {
        $seller = $product->seller;

        $data = $product->toArray();
        $data['seller'] = $seller ? [
            'id' => $seller->id,
            'name' => trim($seller->first_name . ' ' . $seller->last_name),
            'username' => $seller->username,
            'avatar_url' => $seller->profile_photo_path,
            'is_verified' => (bool) ($seller->is_verified ?? false),
        ] : null;
        $data['is_favorited'] = in_array($product->id, $favoriteIds);

        unset($data['deleted_at']);
        return $data;
    }
}
