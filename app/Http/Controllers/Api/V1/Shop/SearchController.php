<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\BlockedUser;
use App\Models\Shop\Product;
use App\Models\Shop\ProductFavorite;
use App\Models\Shop\ShopCommerceAnalyticsEvent;
use App\Support\Shop\ShopProductFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * GET /shop/search/products
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
            'user_id' => 'nullable|integer',
        ]);

        $term = $request->input('q', $request->input('search', ''));
        $perPage = min($request->input('per_page', 20), 50);
        $viewerId = $request->input('user_id');

        if ($viewerId && is_string($term) && strlen(trim($term)) > 1) {
            ShopCommerceAnalyticsEvent::create([
                'user_id' => (int) $viewerId,
                'event_name' => 'search_submit',
                'properties' => ['query' => trim($term)],
                'occurred_at' => now(),
            ]);
        }

        $query = Product::active()->with(['category:id,name,slug', 'seller:id,first_name,last_name,username,profile_photo_path,is_verified']);

        if ($viewerId) {
            $blockedIds = BlockedUser::where('user_id', $viewerId)->pluck('blocked_user_id')
                ->merge(BlockedUser::where('blocked_user_id', $viewerId)->pluck('user_id'))
                ->unique()->toArray();
            if (! empty($blockedIds)) {
                $query->whereNotIn('user_id', $blockedIds);
            }
        }

        if (is_string($term) && trim($term) !== '') {
            $query->search(trim($term));
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $favoriteIds = $viewerId
            ? ProductFavorite::where('user_id', $viewerId)->whereIn('product_id', $products->pluck('id'))->pluck('product_id')->toArray()
            : [];

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $products->map(fn ($p) => ShopProductFormatter::product($p, $favoriteIds)),
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
     * GET /shop/search/trending
     */
    public function trending(Request $request): JsonResponse
    {
        $limit = min($request->input('limit', 10), 30);

        $events = ShopCommerceAnalyticsEvent::query()
            ->where('event_name', 'search_submit')
            ->orderByDesc('id')
            ->limit(800)
            ->get(['properties']);

        $counts = [];
        foreach ($events as $e) {
            $props = $e->properties ?? [];
            $q = strtolower(trim((string) ($props['query'] ?? '')));
            if (strlen($q) < 2) {
                continue;
            }
            $counts[$q] = ($counts[$q] ?? 0) + 1;
        }

        arsort($counts);
        $out = collect($counts)->take($limit)->map(fn ($c, $q) => ['query' => $q, 'count' => $c])->values();

        if ($out->isEmpty()) {
            $fallback = Product::active()->orderByDesc('orders_count')->limit(5)->pluck('title')->map(fn ($t) => ['query' => $t, 'count' => 0]);

            return response()->json(['success' => true, 'data' => $fallback->values()]);
        }

        return response()->json([
            'success' => true,
            'data' => $out,
        ]);
    }

    /**
     * GET /shop/search/suggestions
     */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:255',
            'limit' => 'integer|min:1|max:20',
        ]);

        $q = trim($request->string('q')->toString());
        $limit = min($request->input('limit', 8), 20);

        $titles = Product::active()
            ->where('title', 'like', '%'.$q.'%')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->pluck('title')
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $titles,
        ]);
    }

    /**
     * POST /shop/search/semantic — keyword search fallback until vector search exists.
     */
    public function semantic(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'user_id' => 'nullable|integer',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $request->merge([
            'q' => $request->input('query'),
            'search' => $request->input('query'),
        ]);

        return $this->products($request);
    }
}
