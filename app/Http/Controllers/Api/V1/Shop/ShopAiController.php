<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract stubs for shop AI surfaces; replace with model calls when available.
 */
class ShopAiController extends Controller
{
    public function refreshRecommendations(Request $request): JsonResponse
    {
        return $this->stub('recommendations_refresh');
    }

    public function refreshTrending(Request $request): JsonResponse
    {
        return $this->stub('trending_refresh');
    }

    public function searchIntent(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|max:500']);

        return response()->json([
            'success' => true,
            'data' => [
                'intent' => 'product_browse',
                'slots' => [],
            ],
        ]);
    }

    public function autoTags(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['tags' => []]]);
    }

    public function pricingSuggest(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['suggested_price_tzs' => null]]);
    }

    public function chatAssistant(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['reply' => 'Shop assistant is offline; use /api/ai/ask for backend changes.'],
        ]);
    }

    public function chatSellerSupport(Request $request): JsonResponse
    {
        return $this->chatAssistant($request);
    }

    public function contentProductDescription(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['description' => null]]);
    }

    public function contentAdCopy(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['copy' => null]]);
    }

    public function moderateProduct(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['flags' => [], 'score' => 0.0]]);
    }

    public function moderateReview(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['flags' => [], 'score' => 0.0]]);
    }

    private function stub(string $job): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['job' => $job, 'status' => 'queued_stub'],
        ]);
    }
}
