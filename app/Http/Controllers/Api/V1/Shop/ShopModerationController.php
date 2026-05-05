<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\ShopProductReport;
use App\Models\Shop\ShopSellerBan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopModerationController extends Controller
{
    private function moderatorIds(): array
    {
        return array_map('intval', config('shop.moderator_user_ids', []) ?: []);
    }

    private function assertModerator(int $userId): ?JsonResponse
    {
        if (! in_array($userId, $this->moderatorIds(), true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * GET /shop/moderation/reports
     */
    public function reports(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status' => 'nullable|string|in:pending,reviewed,dismissed',
        ]);

        if ($err = $this->assertModerator($request->integer('user_id'))) {
            return $err;
        }

        $q = ShopProductReport::with(['product:id,title,user_id', 'reporter:id,username'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        } else {
            $q->where('status', 'pending');
        }

        return response()->json([
            'success' => true,
            'data' => $q->limit(200)->get(),
        ]);
    }

    /**
     * PATCH /shop/moderation/reports/{id}
     */
    public function updateReport(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status' => 'required|string|in:pending,reviewed,dismissed',
            'moderator_notes' => 'nullable|string|max:2000',
        ]);

        if ($err = $this->assertModerator($request->integer('user_id'))) {
            return $err;
        }

        $report = ShopProductReport::find($id);
        if (! $report) {
            return response()->json(['success' => false, 'message' => 'Report not found'], 404);
        }

        $report->update([
            'status' => $request->string('status')->toString(),
            'moderator_notes' => $request->input('moderator_notes'),
            'resolved_by' => $request->integer('user_id'),
            'resolved_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $report->fresh()]);
    }

    /**
     * GET /shop/moderation/banned-sellers
     */
    public function bannedSellers(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        if ($err = $this->assertModerator($request->integer('user_id'))) {
            return $err;
        }

        return response()->json([
            'success' => true,
            'data' => ShopSellerBan::with('seller:id,first_name,last_name,username')->orderByDesc('created_at')->get(),
        ]);
    }

    /**
     * POST /shop/moderation/sellers/{sellerUserId}/ban
     */
    public function banSeller(Request $request, int $sellerUserId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:2000',
        ]);

        if ($err = $this->assertModerator($request->integer('user_id'))) {
            return $err;
        }

        ShopSellerBan::updateOrCreate(
            ['seller_user_id' => $sellerUserId],
            ['reason' => $request->input('reason')]
        );

        return response()->json(['success' => true, 'message' => 'Seller banned from shop surface']);
    }
}
