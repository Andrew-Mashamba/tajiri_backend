<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerDashboardController extends Controller
{
    /**
     * GET /shop/seller/{userId}/stats  OR  GET /v1/shop/seller/stats?user_id=
     */
    public function stats(Request $request, ?int $userId = null): JsonResponse
    {
        $userId = $userId ?? $request->input('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id is required'], 422);
        }

        // Product stats
        $productStats = Product::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'active') as active,
                COUNT(*) FILTER (WHERE status = 'draft') as draft,
                COUNT(*) FILTER (WHERE status = 'sold_out') as sold_out,
                COUNT(*) FILTER (WHERE status = 'archived') as archived
            ")
            ->first();

        // Order stats
        $orderStats = Order::where('seller_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'confirmed') as confirmed,
                COUNT(*) FILTER (WHERE status = 'processing') as processing,
                COUNT(*) FILTER (WHERE status = 'shipped') as shipped,
                COUNT(*) FILTER (WHERE status = 'delivered') as delivered,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled
            ")
            ->first();

        // Revenue stats
        $totalRevenue = Order::where('seller_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->sum('total_amount');

        $thisMonthRevenue = Order::where('seller_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $lastMonthRevenue = Order::where('seller_id', $userId)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('total_amount');

        // Rating stats
        $productIds = Product::where('user_id', $userId)->pluck('id');
        $ratingStats = ProductReview::whereIn('product_id', $productIds)
            ->selectRaw('AVG(rating) as average, COUNT(*) as total')
            ->first();

        $ratingDistribution = ProductReview::whereIn('product_id', $productIds)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        // Views
        $totalViews = Product::where('user_id', $userId)->sum('views_count');
        $thisMonthViews = Product::where('user_id', $userId)
            ->where('updated_at', '>=', now()->startOfMonth())
            ->sum('views_count');

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'products' => [
                        'total' => (int) $productStats->total,
                        'active' => (int) $productStats->active,
                        'draft' => (int) $productStats->draft,
                        'sold_out' => (int) $productStats->sold_out,
                        'archived' => (int) $productStats->archived,
                    ],
                    'orders' => [
                        'total' => (int) $orderStats->total,
                        'pending' => (int) $orderStats->pending,
                        'confirmed' => (int) $orderStats->confirmed,
                        'processing' => (int) $orderStats->processing,
                        'shipped' => (int) $orderStats->shipped,
                        'delivered' => (int) $orderStats->delivered,
                        'completed' => (int) $orderStats->completed,
                        'cancelled' => (int) $orderStats->cancelled,
                    ],
                    'revenue' => [
                        'total' => (float) $totalRevenue,
                        'this_month' => (float) $thisMonthRevenue,
                        'last_month' => (float) $lastMonthRevenue,
                        'currency' => 'TZS',
                    ],
                    'rating' => [
                        'average' => round($ratingStats->average ?? 0, 2),
                        'total_reviews' => (int) ($ratingStats->total ?? 0),
                        'distribution' => [
                            '5' => $ratingDistribution[5] ?? 0,
                            '4' => $ratingDistribution[4] ?? 0,
                            '3' => $ratingDistribution[3] ?? 0,
                            '2' => $ratingDistribution[2] ?? 0,
                            '1' => $ratingDistribution[1] ?? 0,
                        ],
                    ],
                    'views' => [
                        'total' => (int) $totalViews,
                        'this_month' => (int) $thisMonthViews,
                    ],
                ],
            ],
        ]);
    }

    /**
     * GET /v1/shop/seller/products
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'string|in:draft,active,sold_out,archived',
        ]);

        $perPage = min($request->input('per_page', 20), 50);

        $query = Product::where('user_id', $request->user_id)
            ->with('category:id,name,slug');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $products->items(),
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
     * GET /v1/shop/seller/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'string|in:pending,confirmed,processing,shipped,delivered,completed,cancelled,refunded',
        ]);

        $perPage = min($request->input('per_page', 20), 50);

        $query = Order::where('seller_id', $request->user_id)
            ->with([
                'product:id,title,thumbnail_url,price,type',
                'buyer:id,first_name,last_name,username,profile_photo_path',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $items = $orders->map(function ($order) {
            $data = $order->toArray();
            if ($order->buyer) {
                $data['buyer'] = [
                    'id' => $order->buyer->id,
                    'name' => trim($order->buyer->first_name . ' ' . $order->buyer->last_name),
                    'username' => $order->buyer->username,
                    'avatar_url' => $order->buyer->profile_photo_path ?? null,
                ];
            }
            unset($data['deleted_at']);
            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
                    'has_more' => $orders->hasMorePages(),
                ],
            ],
        ]);
    }
}
