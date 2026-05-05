<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ShoppingCart;
use App\Support\Shop\ShopProductFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * Flutter Cart.fromJson expects top-level keys: items, subtotal, delivery_total, grand_total, currency
     *
     * @return array<string, mixed>
     */
    private function cartPayload(int $userId): array
    {
        $cart = ShoppingCart::where('user_id', $userId)->first();

        if (! $cart) {
            return [
                'items' => [],
                'subtotal' => 0.0,
                'delivery_total' => 0.0,
                'grand_total' => 0.0,
                'currency' => 'TZS',
            ];
        }

        $items = CartItem::where('cart_id', $cart->id)
            ->with(['product' => fn ($q) => $q->with(['seller:id,first_name,last_name,username,profile_photo_path,is_verified', 'category:id,name,slug'])])
            ->get();

        $subtotal = 0.0;
        $deliveryTotal = 0.0;
        $formattedItems = [];

        foreach ($items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $lineTotal = $item->quantity * (float) $product->price;
            $subtotal += $lineTotal;

            // Delivery only when buyer would use delivery on this line (UI does not send per-line method in cart; keep 0 here)
            $formattedItems[] = [
                'product_id' => $product->id,
                'quantity' => $item->quantity,
                'added_at' => $item->created_at?->toIso8601String(),
                'product' => ShopProductFormatter::cartLineProduct($product),
                'line_total' => $lineTotal,
            ];
        }

        return [
            'items' => $formattedItems,
            'subtotal' => round($subtotal, 2),
            'delivery_total' => round($deliveryTotal, 2),
            'grand_total' => round($subtotal + $deliveryTotal, 2),
            'currency' => 'TZS',
        ];
    }

    /**
     * GET /v1/shop/cart
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = (int) $request->input('user_id');

        return response()->json([
            'success' => true,
            'data' => $this->cartPayload($userId),
        ]);
    }

    /**
     * POST /v1/shop/cart/items
     */
    public function addItem(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'integer|min:1',
        ]);

        $userId = (int) $request->input('user_id');
        $product = Product::find($request->product_id);

        if (! $product || ! $product->isAvailable()) {
            return response()->json(['success' => false, 'message' => 'Product is not available'], 422);
        }

        if ($product->user_id == $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot add your own product to cart'], 422);
        }

        $quantity = $request->input('quantity', 1);

        if (! $product->hasStock($quantity)) {
            return response()->json(['success' => false, 'message' => 'Insufficient stock. Available: '.$product->stock_quantity], 422);
        }

        $cart = ShoppingCart::getOrCreate($userId);

        $existingCount = CartItem::where('cart_id', $cart->id)->count();
        if ($existingCount >= 50) {
            return response()->json(['success' => false, 'message' => 'Cart is full (max 50 items)'], 422);
        }

        $item = CartItem::where('cart_id', $cart->id)->where('product_id', $product->id)->first();

        if ($item) {
            $newQty = $item->quantity + $quantity;
            if (! $product->hasStock($newQty)) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock. Available: '.$product->stock_quantity], 422);
            }
            $item->update(['quantity' => $newQty]);
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart',
            'data' => $this->cartPayload($userId),
        ]);
    }

    /**
     * PUT /v1/shop/cart/items/{productId}
     */
    public function updateItem(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        $userId = (int) $request->input('user_id');
        $cart = ShoppingCart::where('user_id', $userId)->first();

        if (! $cart) {
            return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
        }

        $item = CartItem::where('cart_id', $cart->id)->where('product_id', $productId)->first();
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Item not in cart'], 404);
        }

        $product = Product::find($productId);
        if (! $product || ! $product->hasStock($request->quantity)) {
            return response()->json(['success' => false, 'message' => 'Insufficient stock'], 422);
        }

        $item->update(['quantity' => $request->quantity]);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated',
            'data' => $this->cartPayload($userId),
        ]);
    }

    /**
     * DELETE /v1/shop/cart/items/{productId}
     */
    public function removeItem(Request $request, int $productId): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = (int) $request->input('user_id');

        $cart = ShoppingCart::where('user_id', $userId)->first();
        if (! $cart) {
            return response()->json(['success' => false, 'message' => 'Cart not found'], 404);
        }

        $deleted = CartItem::where('cart_id', $cart->id)->where('product_id', $productId)->delete();

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Item not in cart'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
            'data' => $this->cartPayload($userId),
        ]);
    }

    /**
     * DELETE /v1/shop/cart
     */
    public function clear(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $userId = (int) $request->input('user_id');

        $cart = ShoppingCart::where('user_id', $userId)->first();
        if ($cart) {
            CartItem::where('cart_id', $cart->id)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared',
        ]);
    }
}
