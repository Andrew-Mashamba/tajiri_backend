<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\ShopAffiliateCommission;
use App\Models\Shop\ShopAffiliateLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopAffiliateController extends Controller
{
    /**
     * GET /shop/affiliate/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $uid = $request->integer('user_id');

        $pending = ShopAffiliateCommission::where('referrer_user_id', $uid)->where('status', 'pending')->sum('amount_tzs');
        $paid = ShopAffiliateCommission::where('referrer_user_id', $uid)->where('status', 'paid')->sum('amount_tzs');
        $links = ShopAffiliateLink::where('user_id', $uid)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'referrer_user_id' => $uid,
                'links_count' => $links,
                'pending_commission_tzs' => (float) $pending,
                'paid_commission_tzs' => (float) $paid,
            ],
        ]);
    }

    /**
     * GET /shop/affiliate/links
     */
    public function links(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $items = ShopAffiliateLink::where('user_id', $request->integer('user_id'))->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * POST /shop/affiliate/links
     */
    public function storeLink(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'label' => 'nullable|string|max:255',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $link = ShopAffiliateLink::create([
            'user_id' => $request->integer('user_id'),
            'label' => $request->input('label'),
            'commission_percent' => $request->input('commission_percent', 5),
        ]);

        return response()->json(['success' => true, 'data' => $link], 201);
    }

    /**
     * GET /shop/affiliate/commissions
     */
    public function commissions(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $rows = ShopAffiliateCommission::where('referrer_user_id', $request->integer('user_id'))
            ->with('link:id,code')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /shop/affiliate/payouts — financial settlement uses main wallet pipelines.
     */
    public function payouts(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        return response()->json([
            'success' => true,
            'data' => [],
            'meta' => [
                'note' => 'Use /wallet and wallet transactions for cash movement; affiliate payouts ledger TBD.',
            ],
        ]);
    }
}
