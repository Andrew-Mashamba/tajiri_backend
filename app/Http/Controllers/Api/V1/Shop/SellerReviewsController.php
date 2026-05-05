<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerReviewsController extends Controller
{
    /**
     * GET /shop/sellers/{sellerUserId}/reviews
     */
    public function index(Request $request, int $sellerUserId): JsonResponse
    {
        $perPage = min($request->input('per_page', 20), 50);
        $userId = $request->input('user_id');

        $productIds = Product::query()->where('user_id', $sellerUserId)->pluck('id');
        if ($productIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'stats' => [
                    'average_rating' => 0.0,
                    'total_reviews' => 0,
                    'rating_distribution' => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_more' => false,
                ],
            ]);
        }

        $query = ProductReview::whereIn('product_id', $productIds)
            ->with(['user:id,first_name,last_name,username,profile_photo_path']);

        $reviews = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $list = $reviews->map(function (ProductReview $r) {
            $row = $r->toArray();
            if ($r->relationLoaded('user') && $r->user) {
                $row['user'] = [
                    'id' => $r->user->id,
                    'first_name' => $r->user->first_name,
                    'last_name' => $r->user->last_name,
                    'username' => $r->user->username,
                    'profile_photo_path' => $r->user->profile_photo_path,
                ];
            }
            $row['product_id'] = $r->product_id;

            return $row;
        })->values();

        $distribution = ProductReview::whereIn('product_id', $productIds)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        $avg = ProductReview::whereIn('product_id', $productIds)->avg('rating');
        $total = ProductReview::whereIn('product_id', $productIds)->count();

        return response()->json([
            'success' => true,
            'data' => $list,
            'stats' => [
                'average_rating' => round((float) ($avg ?? 0), 2),
                'total_reviews' => (int) $total,
                'rating_distribution' => [
                    '5' => (int) ($distribution[5] ?? 0),
                    '4' => (int) ($distribution[4] ?? 0),
                    '3' => (int) ($distribution[3] ?? 0),
                    '2' => (int) ($distribution[2] ?? 0),
                    '1' => (int) ($distribution[1] ?? 0),
                ],
            ],
            'meta' => [
                'seller_user_id' => $sellerUserId,
                'viewer_user_id' => $userId,
            ],
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'total_pages' => $reviews->lastPage(),
                'has_more' => $reviews->hasMorePages(),
            ],
        ]);
    }
}
