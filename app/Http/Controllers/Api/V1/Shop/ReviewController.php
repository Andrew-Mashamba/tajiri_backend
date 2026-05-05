<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ReviewHelpfulVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    /**
     * Shape for TAJIRI Flutter Review.fromJson.
     *
     * @param  array<int>  $helpfulIds
     */
    private function formatReview(ProductReview $review, array $helpfulIds = []): array
    {
        $data = $review->toArray();
        if ($review->relationLoaded('user') && $review->user) {
            $data['user'] = [
                'id' => $review->user->id,
                'first_name' => $review->user->first_name,
                'last_name' => $review->user->last_name,
                'username' => $review->user->username,
                'profile_photo_path' => $review->user->profile_photo_path,
            ];
        }
        $data['is_helpful'] = in_array($review->id, $helpfulIds, true);
        unset($data['deleted_at']);

        return $data;
    }

    /**
     * GET /v1/shop/products/{productId}/reviews
     */
    public function index(Request $request, int $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $perPage = min($request->input('per_page', 20), 50);
        $sortBy = $request->input('sort_by', 'newest');
        $userId = $request->input('user_id');

        $query = ProductReview::where('product_id', $productId)
            ->with('user:id,first_name,last_name,username,profile_photo_path');

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->input('rating'));
        }

        $query = match ($sortBy) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'highest' => $query->orderBy('rating', 'desc')->orderBy('created_at', 'desc'),
            'lowest' => $query->orderBy('rating', 'asc')->orderBy('created_at', 'desc'),
            'helpful' => $query->orderBy('helpful_count', 'desc')->orderBy('created_at', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $reviews = $query->paginate($perPage);

        $helpfulIds = [];
        if ($userId) {
            $helpfulIds = ReviewHelpfulVote::where('user_id', $userId)
                ->whereIn('review_id', $reviews->pluck('id'))
                ->pluck('review_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $list = $reviews->map(fn ($r) => $this->formatReview($r, $helpfulIds))->values()->all();

        $distribution = ProductReview::where('product_id', $productId)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => $list,
            'stats' => [
                'average_rating' => (float) $product->rating,
                'total_reviews' => (int) $product->reviews_count,
                'rating_distribution' => [
                    '5' => (int) ($distribution[5] ?? 0),
                    '4' => (int) ($distribution[4] ?? 0),
                    '3' => (int) ($distribution[3] ?? 0),
                    '2' => (int) ($distribution[2] ?? 0),
                    '1' => (int) ($distribution[1] ?? 0),
                ],
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'total_pages' => $reviews->lastPage(),
                'last_page' => $reviews->lastPage(),
                'has_more' => $reviews->hasMorePages(),
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
        ]);

        $userId = (int) $request->input('user_id');
        $product = Product::find($productId);

        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $existing = ProductReview::where('product_id', $productId)->where('user_id', $userId)->first();
        if ($existing) {
            return response()->json(['success' => false, 'message' => 'You have already reviewed this product'], 422);
        }

        $eligibleOrder = Order::where('buyer_id', $userId)
            ->where('product_id', $productId)
            ->whereIn('status', [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED])
            ->orderByDesc('id')
            ->first();

        if (! $eligibleOrder) {
            return response()->json(['success' => false, 'message' => 'You can only review products you have purchased and received'], 422);
        }

        $imagePaths = [];

        foreach ($request->allFiles() as $key => $file) {
            if (! str_starts_with($key, 'images')) {
                continue;
            }
            if (is_array($file)) {
                foreach ($file as $sub) {
                    if ($sub && $sub->isValid()) {
                        $path = $sub->store("reviews/product-{$productId}/user-{$userId}", 'public');
                        $imagePaths[] = Storage::disk('public')->url($path);
                    }
                }
            } elseif ($file && $file->isValid()) {
                $path = $file->store("reviews/product-{$productId}/user-{$userId}", 'public');
                $imagePaths[] = Storage::disk('public')->url($path);
            }
        }

        if (empty($imagePaths) && $request->has('images')) {
            $raw = $request->input('images');
            if (is_array($raw)) {
                $imagePaths = array_values(array_filter(array_map('strval', $raw)));
            }
        }

        $review = ProductReview::create([
            'product_id' => $productId,
            'user_id' => $userId,
            'order_id' => $eligibleOrder->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'images' => $imagePaths ?: null,
            'is_verified_purchase' => true,
        ]);

        $product->recalculateRating();

        $review->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully',
            'data' => $this->formatReview($review, []),
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
        ]);

        $review = ProductReview::find($id);
        if (! $review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        if ($review->user_id != $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($review->created_at->diffInDays(now()) > 30) {
            return response()->json(['success' => false, 'message' => 'Reviews can only be edited within 30 days'], 422);
        }

        $review->update($request->only(['rating', 'comment']));

        $review->product->recalculateRating();

        $review->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => 'Review updated',
            'data' => $this->formatReview($review, []),
        ]);
    }

    /**
     * DELETE /v1/shop/reviews/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $review = ProductReview::find($id);
        if (! $review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        if ($review->user_id != $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $product = $review->product;
        $review->delete();

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
        if (! $review) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        if ($review->user_id == $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot vote on your own review'], 422);
        }

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
