<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\Shop\Product;
use App\Models\Shop\ShopLiveSessionProduct;
use App\Support\Shop\ShopProductFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopLiveCommerceController extends Controller
{
    /**
     * GET /shop/live/sessions/{liveStreamId}/products
     */
    public function listProducts(Request $request, int $liveStreamId): JsonResponse
    {
        if (! LiveStream::where('id', $liveStreamId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Live session not found'], 404);
        }

        $idOrder = ShopLiveSessionProduct::where('live_stream_id', $liveStreamId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('product_id')
            ->values()
            ->all();

        if ($idOrder === []) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $byId = Product::active()->whereIn('id', $idOrder)->get()->keyBy('id');
        $products = collect($idOrder)->map(fn ($pid) => $byId->get($pid))->filter()->values();

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => ShopProductFormatter::product($p, [])),
        ]);
    }

    /**
     * POST /shop/live/sessions/{liveStreamId}/products
     */
    public function attachProducts(Request $request, int $liveStreamId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'product_ids' => 'required|array|min:1|max:50',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $stream = LiveStream::find($liveStreamId);
        if (! $stream) {
            return response()->json(['success' => false, 'message' => 'Live session not found'], 404);
        }

        if ($stream->user_id !== $request->integer('user_id')) {
            return response()->json(['success' => false, 'message' => 'Only host can pin products'], 403);
        }

        $order = 0;
        foreach ($request->input('product_ids', []) as $pid) {
            ShopLiveSessionProduct::updateOrCreate(
                ['live_stream_id' => $liveStreamId, 'product_id' => (int) $pid],
                ['sort_order' => $order++]
            );
        }

        return $this->listProducts($request, $liveStreamId);
    }

    /**
     * POST /shop/live/sessions/{liveStreamId}/auction/bid
     */
    public function auctionBid(Request $request, int $liveStreamId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Live auctions are not enabled',
        ], 422);
    }
}
