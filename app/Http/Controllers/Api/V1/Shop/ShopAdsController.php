<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\ShopAdCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopAdsController extends Controller
{
    /**
     * GET /shop/ads/campaigns
     */
    public function campaigns(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $items = ShopAdCampaign::where('user_id', $request->integer('user_id'))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * POST /shop/ads/campaigns
     */
    public function storeCampaign(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|in:draft,active,paused',
        ]);

        $c = ShopAdCampaign::create([
            'user_id' => $request->integer('user_id'),
            'name' => $request->string('name')->toString(),
            'status' => $request->input('status', 'draft'),
        ]);

        return response()->json(['success' => true, 'data' => $c], 201);
    }

    /**
     * GET/PATCH /shop/ads/campaigns/{id}
     */
    public function showCampaign(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $c]);
    }

    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,active,paused',
            'daily_budget_tzs' => 'nullable|numeric|min:0',
            'total_budget_tzs' => 'nullable|numeric|min:0',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $c->fill($request->only([
            'name', 'status', 'daily_budget_tzs', 'total_budget_tzs', 'start_at', 'end_at',
        ]));
        $c->save();

        return response()->json(['success' => true, 'data' => $c->fresh()]);
    }

    public function destroyCampaign(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $c->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /shop/ads/campaigns/{id}/schedule
     */
    public function scheduleCampaign(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'start_at' => $request->input('starts_at', $request->input('start_at')),
            'end_at' => $request->input('ends_at', $request->input('end_at')),
        ]);

        return $this->updateCampaign($request, $id);
    }

    /**
     * PATCH /shop/ads/campaigns/{id}/budget
     */
    public function budgetCampaign(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'daily_budget_tzs' => 'nullable|numeric|min:0',
            'total_budget_tzs' => 'nullable|numeric|min:0',
        ]);

        return $this->updateCampaign($request, $id);
    }

    /**
     * GET /shop/ads/targeting/segments — static placeholders.
     */
    public function targetingSegments(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => config('shop.ads.default_segments', []),
        ]);
    }

    /**
     * POST /shop/ads/campaigns/{id}/targeting
     */
    public function targeting(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'targeting' => 'required|array',
        ]);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $c->update(['targeting' => $request->input('targeting')]);

        return response()->json(['success' => true, 'data' => $c->fresh()]);
    }

    /**
     * GET /shop/ads/creatives
     */
    public function creatives(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $items = ShopAdCampaign::where('user_id', $request->integer('user_id'))
            ->whereNotNull('creative')
            ->get(['id', 'name', 'creative', 'status']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * POST /shop/ads/campaigns/{id}/creatives
     */
    public function storeCreative(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'creative' => 'required|array',
        ]);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $c->update(['creative' => $request->input('creative')]);

        return response()->json(['success' => true, 'data' => $c->fresh()]);
    }

    /**
     * POST /shop/ads/auction — same lightweight selection as serve.
     */
    public function auction(Request $request): JsonResponse
    {
        return $this->serve($request);
    }

    /**
     * POST /shop/ads/serve
     */
    public function serve(Request $request): JsonResponse
    {
        $campaign = ShopAdCampaign::where('status', 'active')->orderBy('id')->first();

        return response()->json([
            'success' => true,
            'data' => $campaign ? [
                'campaign_id' => $campaign->id,
                'creative' => $campaign->creative ?? [],
                'targeting' => $campaign->targeting ?? [],
            ] : null,
        ]);
    }

    public function impression(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        return response()->noContent();
    }

    public function click(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        return response()->noContent();
    }

    public function conversion(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        return response()->noContent();
    }

    /**
     * GET /shop/ads/campaigns/{id}/analytics
     */
    public function campaignAnalytics(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $c = ShopAdCampaign::find($id);
        if (! $c || ! $this->owns($request->integer('user_id'), $c)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'campaign_id' => $c->id,
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0,
                'spend_tzs' => 0,
            ],
        ]);
    }

    /**
     * GET /shop/ads/invoices
     */
    public function invoices(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * GET /shop/ads/moderation/queue
     */
    public function moderationQueue(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        if (! in_array($request->integer('user_id'), array_map('intval', config('shop.moderator_user_ids', []) ?: []), true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $items = ShopAdCampaign::orderByDesc('updated_at')->limit(100)->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * PATCH /shop/ads/moderation/{id}
     */
    public function moderationUpdate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status' => 'required|string|in:draft,active,paused',
        ]);

        if (! in_array($request->integer('user_id'), array_map('intval', config('shop.moderator_user_ids', []) ?: []), true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $c = ShopAdCampaign::find($id);
        if (! $c) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $c->update(['status' => $request->string('status')->toString()]);

        return response()->json(['success' => true, 'data' => $c->fresh()]);
    }

    private function owns(int $userId, ShopAdCampaign $c): bool
    {
        return $c->user_id === $userId;
    }
}
