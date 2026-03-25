<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ReviewHelpfulVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * GET /v1/shop/products/{productId}/reviews
     */
    public function index(Request $request, int $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $perPage = min($request->input('per_page', 20), 50);
        $sortBy = $request->input('sort_by', 'newest');
        $userId = $request->input('user_id');

        $query = ProductReview::where('product_id', $productId)
            ->with('user:id,first_name,last_name,username,profile_photo_path');

        $query = match ($sortBy) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'highest' => $query->orderBy('rating', 'desc')->orderBy('created_at', 'desc'),
            'lowest' => $query->orderBy('rating', 'asc')->orderBy('created_at', 'desc'),
            'helpful' => $query->orderBy('helpful_count', 'desc')->orderBy('created_at', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $reviews = $query->paginate($perPage);

        // Check which reviews user has voted helpful
        $helpfulIds = [];
        if ($userId) {
            $helpfulIds = ReviewHelpfulVote::where('user_id', $userId)
                ->whereIn('review_id', $reviews->pluck('id'))
                ->pluck('review_id')
                ->toArray();
        }

        $items = $reviews->map(function ($review) use ($helpfulIds) {
            $data = $review->toArray();
            if ($review->user) {
                $data['user'] = [
                    'id' => $review->user->id,
                    'name' => trim($review->user->first_name . ' ' . $review->user->last_name),
                    'username' => $review->user->username,
                    'avatar_url' => $review->user->profile_photo_path,
                ];
            }
            $data['is_helpful_by_me'] = in_array($review->id, $helpfulIds);
            unset($data['deleted_at']);
            return $data;
        });

        // Rating distribution
        $distribution = ProductReview::where('product_id', $productId)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'summary' => [
                    'average_rating' => (float) $product->rating,
                    'total_reviews' => $product->reviews_count,
                    'distribution' => [
                        '5' => $distribution[5] ?? 0,
                        '4' => $distribution[4] ?? 0,
                        '3' => $distribution[3] ?? 0,
                        '2' => $distribution[2] ?? 0,
                        '1' => $distribution[1] ?? 0,
                    ],
                ],
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'total_pages' => $reviews->lastPage(),
                    'has_more' => $reviews->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * POST /v1/shop/products/{productId}/reviews
     */
    public function store(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'rating' => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:2000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'string',
        ]);

        $userId = $request->input('user_id');
        $product = Product::find($productId);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Check if already reviewed
        $existing = ProductReview::where('product_id', $productId)->where('user_id', $userId)->first();
        if ($existing) {
            return response()->json(['success' => false, 'message' => 'You have already reviewed this product'], 422);
        }

        // Check if user has a completed order for this product
        $completedOrder = Order::where('buyer_id', $userId)
            ->where('product_id', $productId)
            ->where('status', Order::STATUS_COMPLETED)
            ->first();

        if (!$completedOrder) {
            return response()->json(['success' => false, 'message' => 'You can only review products you have purchased and received'], 422);
        }

        $review = ProductReview::create([
            'product_id' => $productId,
            'user_id' => $userId,
            'order_id' => $completedOrder->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'images' => $request->images,
            'is_verified_purchase' => true,
        ]);

        // Recalculate product rating
        $product->recalculateRating();

        $review->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully',
            'data' => ['review' => $review],
        ], 201);
    }

    /**
     * PUT /v1/shop/reviews/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'rating' => 'integer|between:1,5',
            'comment' => 'nullable|string|max:2000',
            'images' => 'nullable|array|max:5',
        ]);

        $review = ProductReview::find($id);
        if (!$review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        if ($review->user_id != $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Can only update within 30 days
        if ($review->created_at->diffInDays(now()) > 30) {
            return response()->json(['success' => false, 'message' => 'Reviews can only be edited within 30 days'], 422);
        }

        $review->update($request->only(['rating', 'comment', 'images']));

        // Recalculate product rating
        $review->product->recalculateRating();

        return response()->json([
            'success' => true,
            'message' => 'Review updated',
            'data' => ['review' => $review],
        ]);
    }

    /**
     * DELETE /v1/shop/reviews/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $review = ProductReview::find($id);
        if (!$review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        if ($review->user_id != $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $product = $review->product;
        $review->delete();

        // Recalculate product rating
        $product->recalculateRating();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted',
        ]);
    }

    /**
     * POST /v1/shop/reviews/{id}/helpful
     */
    public function markHelpful(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = $request->input('user_id');

        $review = ProductReview::find($id);
        if (!$review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        // Cannot vote on own review
        if ($review->user_id == $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot vote on your own review'], 422);
        }

        // Toggle vote
        $existing = ReviewHelpfulVote::where('review_id', $id)->where('user_id', $userId)->first();

        if ($existing) {
            $existing->delete();
            $review->recalculateHelpful();
            return response()->json([
                'success' => true,
                'message' => 'Helpful vote removed',
                'data' => ['helpful_count' => $review->fresh()->helpful_count, 'is_helpful' => false],
            ]);
        }

        ReviewHelpfulVote::create(['review_id' => $id, 'user_id' => $userId]);
        $review->recalculateHelpful();

        return response()->json([
            'success' => true,
            'message' => 'Marked as helpful',
            'data' => ['helpful_count' => $review->fresh()->helpful_count, 'is_helpful' => true],
        ]);
    }
}
