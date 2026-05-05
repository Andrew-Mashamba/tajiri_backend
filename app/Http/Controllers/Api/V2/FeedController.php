<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\ContentEngine\ServingPipelineService;
use App\Traits\ChecksFeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    use ChecksFeatureFlags;

    public function feed(Request $request): JsonResponse
    {
        $request->validate([
            'feed_type' => 'required|in:for_you,friends,discover,trending,nearby,shorts,audio',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $userId = $request->user()->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        $feedType = $request->input('feed_type', 'for_you');
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', config('content-engine.serving.per_page_default', 20));

        // Check feature flag
        $flagName = "feed_{$feedType}";
        if (!self::isFeatureEnabled($flagName, $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'This feed type is not available yet',
                'fallback' => true,
            ], 404);
        }

        try {
            $result = ServingPipelineService::serveFeed($feedType, $userId, $page, $perPage);
            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error("V2 Feed error", ['feed_type' => $feedType, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Feed generation failed',
                'fallback' => true,
            ], 500);
        }
    }
}
