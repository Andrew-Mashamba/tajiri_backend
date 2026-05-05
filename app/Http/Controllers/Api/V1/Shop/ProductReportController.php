<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Product;
use App\Models\Shop\ShopProductReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductReportController extends Controller
{
    /**
     * POST /shop/products/{productId}/reports
     */
    public function store(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'reason' => 'required|string|max:64',
            'detail' => 'nullable|string|max:2000',
        ]);

        $product = Product::find($productId);
        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $report = ShopProductReport::create([
            'user_id' => $request->integer('user_id'),
            'product_id' => $productId,
            'reason' => $request->string('reason')->toString(),
            'detail' => $request->input('detail'),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted',
            'data' => $report->toArray(),
        ], 201);
    }
}
