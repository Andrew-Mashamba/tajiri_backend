<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\ContentEngine\ServingPipelineService;
use App\Traits\ChecksFeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use ChecksFeatureFlags;

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:200',
            'types' => 'nullable|string',
            'category' => 'nullable|string',
            'region' => 'nullable|string',
            'sort' => 'nullable|in:relevance,trending,newest',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $userId = $request->user()->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        // Check feature flag
        if (!self::isFeatureEnabled('search_v2', $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Search v2 is not available yet',
                'fallback' => true,
            ], 404);
        }

        $query = $request->input('q');
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', config('content-engine.serving.per_page_default', 20));

        $filters = array_filter([
            'types' => $request->input('types') ? explode(',', $request->input('types')) : null,
            'category' => $request->input('category'),
            'region' => $request->input('region'),
            'sort' => $request->input('sort', 'relevance'),
        ]);

        try {
            $result = ServingPipelineService::serveSearch($query, $userId, $page, $perPage, $filters);
            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error("V2 Search error", ['query' => $query, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'fallback' => true,
            ], 500);
        }
    }
}
