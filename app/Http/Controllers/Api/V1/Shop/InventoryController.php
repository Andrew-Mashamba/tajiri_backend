<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ShopInventoryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * GET /shop/products/{productId}/stock
     */
    public function stock(Request $request, int $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $product->id,
                'stock_quantity' => (int) $product->stock_quantity,
                'reserved_quantity' => 0,
                'updated_at' => $product->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * PATCH /shop/products/{productId}/stock
     */
    public function patchStock(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'quantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $product = Product::find($productId);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $uid = $request->integer('user_id');
        if (! $product->isOwnedBy($uid)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $before = (int) $product->stock_quantity;
        $after = $request->integer('quantity');
        $delta = $after - $before;

        DB::transaction(function () use ($product, $after, $delta, $uid, $request) {
            $product->update(['stock_quantity' => $after]);
            ShopInventoryAdjustment::create([
                'product_id' => $product->id,
                'changed_by' => $uid,
                'quantity_delta' => $delta,
                'quantity_after' => $after,
                'reason' => $request->input('reason', 'manual_adjustment'),
            ]);
            if ($after <= 0 && $product->status === 'active') {
                $product->update(['status' => 'sold_out']);
            }
        });

        $product->refresh();

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $product->id,
                'stock_quantity' => (int) $product->stock_quantity,
                'status' => $product->status,
            ],
        ]);
    }

    /**
     * GET /shop/products/{productId}/inventory/history
     */
    public function history(Request $request, int $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $request->validate([
            'user_id' => 'nullable|integer',
        ]);

        if ($request->filled('user_id') && ! $product->isOwnedBy($request->integer('user_id'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $rows = ShopInventoryAdjustment::where('product_id', $productId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * POST /shop/inventory/sync — mobile delta sync placeholder (ack).
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'device_id' => 'nullable|string|max:128',
            'items' => 'nullable|array|max:200',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sync acknowledged',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
