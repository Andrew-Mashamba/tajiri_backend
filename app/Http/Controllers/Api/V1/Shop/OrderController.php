<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\CartItem;
use App\Models\Shop\Order;
use App\Models\Shop\OrderStatusHistory;
use App\Models\Shop\Product;
use App\Models\Shop\ShoppingCart;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Shop\ShopPromo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Optional wallet PIN when provided on checkout / single order.
     */
    private function verifyOptionalWalletPin(Request $request, int $buyerId): ?JsonResponse
    {
        if (! $request->filled('pin')) {
            return null;
        }

        $wallet = Wallet::getOrCreate($buyerId);
        if (! $wallet->hasPin()) {
            return null;
        }

        if (! $wallet->verifyPin($request->string('pin')->toString())) {
            return response()->json(['success' => false, 'message' => 'Invalid wallet PIN'], 422);
        }

        return null;
    }

    /**
     * POST /v1/shop/orders
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'delivery_method' => 'required|string|in:pickup,delivery,shipping,digital',
            'delivery_address' => 'required_if:delivery_method,delivery,shipping|nullable|string|max:500',
            'delivery_notes' => 'nullable|string|max:500',
            'pin' => 'nullable|string|max:32',
            'promo_code' => 'nullable|string|max:64',
        ]);

        if ($err = $this->verifyOptionalWalletPin($request, (int) $request->user_id)) {
            return $err;
        }

        $buyerId = (int) $request->user_id;
        $product = Product::find($request->product_id);

        if (! $product || ! $product->isAvailable()) {
            return response()->json(['success' => false, 'message' => 'Product is not available'], 422);
        }

        if ($product->user_id == $buyerId) {
            return response()->json(['success' => false, 'message' => 'Cannot buy your own product'], 422);
        }

        if (! $product->hasStock($request->quantity)) {
            return response()->json(['success' => false, 'message' => 'Insufficient stock. Available: '.$product->stock_quantity], 422);
        }

        if ($product->type === 'digital' && $request->delivery_method !== 'digital') {
            return response()->json(['success' => false, 'message' => 'Digital products must use digital delivery'], 422);
        }

        $unitPrice = (float) $product->price;
        $subtotal = $unitPrice * $request->quantity;
        $deliveryFee = in_array($request->delivery_method, ['delivery', 'shipping'], true)
            ? (float) ($product->delivery_fee ?? 0)
            : 0.0;
        $lineGross = $subtotal + $deliveryFee;

        $discount = 0.0;
        if ($request->filled('promo_code')) {
            $pr = ShopPromo::compute($request->string('promo_code')->toString(), $lineGross);
            if ($pr['valid']) {
                $discount = min((float) $pr['discount'], $lineGross);
            }
        }

        $totalAmount = round(max(0.0, $lineGross - $discount), 2);
        if ($lineGross > 0 && $discount > 0) {
            $subPortion = $discount * ($subtotal / $lineGross);
            $delPortion = $discount * ($deliveryFee / $lineGross);
            $subtotal = round(max(0.0, $subtotal - $subPortion), 2);
            $deliveryFee = round(max(0.0, $deliveryFee - $delPortion), 2);
        }

        $wallet = Wallet::getOrCreate($buyerId);
        if (! $wallet->canAfford($totalAmount)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance. Required: '.number_format($totalAmount, 2).' TZS, Available: '.number_format((float) $wallet->balance, 2).' TZS',
            ], 422);
        }

        $order = DB::transaction(function () use ($request, $product, $buyerId, $unitPrice, $subtotal, $deliveryFee, $totalAmount, $wallet) {
            $balanceBefore = $wallet->balance;
            $wallet->decrement('balance', $totalAmount);
            $wallet->refresh();

            $transaction = $wallet->transactions()->create([
                'transaction_id' => WalletTransaction::generateId(),
                'user_id' => $buyerId,
                'type' => 'payment',
                'amount' => $totalAmount,
                'fee' => 0,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'status' => 'completed',
                'description' => 'Shop purchase: '.$product->title,
                'completed_at' => now(),
            ]);

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'buyer_id' => $buyerId,
                'seller_id' => $product->user_id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total_amount' => $totalAmount,
                'currency' => 'TZS',
                'status' => Order::STATUS_PENDING,
                'delivery_method' => $request->delivery_method,
                'delivery_address' => $request->delivery_address,
                'delivery_notes' => $request->delivery_notes,
                'wallet_transaction_id' => $transaction->id,
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => Order::STATUS_PENDING,
                'notes' => 'Order placed',
                'changed_by' => $buyerId,
            ]);

            $product->increment('orders_count');

            return $order;
        });

        $order->load([
            'product' => fn ($q) => $q->select('id', 'title', 'slug', 'thumbnail_url', 'images', 'price', 'type', 'condition', 'currency', 'category_id')
                ->with('category:id,name,slug'),
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * POST /v1/shop/checkout — multi-line wallet checkout (Flutter ShopService.checkout)
     */
    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.delivery_method' => 'required|string|in:pickup,delivery,shipping,digital',
            'items.*.delivery_address' => 'nullable|string|max:500',
            'items.*.delivery_notes' => 'nullable|string|max:500',
            'promo_code' => 'nullable|string|max:64',
            'pin' => 'nullable|string|max:32',
            'payment_method' => 'nullable|string|max:32',
            'mpesa_phone' => 'nullable|string|max:20',
        ]);

        $buyerId = (int) $request->user_id;

        if ($err = $this->verifyOptionalWalletPin($request, $buyerId)) {
            return $err;
        }

        foreach ($request->items as $idx => $row) {
            if (in_array($row['delivery_method'], ['delivery', 'shipping'], true)
                && empty($row['delivery_address'])) {
                return response()->json([
                    'success' => false,
                    'message' => "delivery_address required for item #".($idx + 1),
                ], 422);
            }
        }

        $parsed = [];
        $grandPreDiscount = 0.0;

        foreach ($request->items as $idx => $row) {
            $product = Product::find((int) $row['product_id']);

            if (! $product || ! $product->isAvailable()) {
                return response()->json(['success' => false, 'message' => 'Product #'.((int) $row['product_id']).' is not available'], 422);
            }

            if ($product->user_id === $buyerId) {
                return response()->json(['success' => false, 'message' => 'Cannot buy your own product'], 422);
            }

            $qty = (int) $row['quantity'];
            if (! $product->hasStock($qty)) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock for '.$product->title], 422);
            }

            if ($product->type === 'digital' && $row['delivery_method'] !== 'digital') {
                return response()->json(['success' => false, 'message' => 'Digital products must use digital delivery'], 422);
            }

            $unitPrice = (float) $product->price;
            $subtotal = $unitPrice * $qty;
            $deliveryFee = in_array($row['delivery_method'], ['delivery', 'shipping'], true)
                ? (float) ($product->delivery_fee ?? 0)
                : 0.0;
            $lineGross = $subtotal + $deliveryFee;
            $grandPreDiscount += $lineGross;

            $parsed[] = [
                'product' => $product,
                'quantity' => $qty,
                'delivery_method' => $row['delivery_method'],
                'delivery_address' => $row['delivery_address'] ?? null,
                'delivery_notes' => $row['delivery_notes'] ?? null,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'line_gross' => $lineGross,
            ];
        }

        $discount = 0.0;
        if ($request->filled('promo_code')) {
            $pr = ShopPromo::compute($request->string('promo_code')->toString(), $grandPreDiscount);
            if ($pr['valid']) {
                $discount = min((float) $pr['discount'], $grandPreDiscount);
            }
        }

        $grandCharged = round(max(0.0, $grandPreDiscount - $discount), 2);

        $wallet = Wallet::getOrCreate($buyerId);
        if (! $wallet->canAfford($grandCharged)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance. Required: '.number_format($grandCharged, 2).' TZS, Available: '.number_format((float) $wallet->balance, 2).' TZS',
            ], 422);
        }

        $ordersOut = DB::transaction(function () use ($parsed, $buyerId, $grandPreDiscount, $discount, $wallet) {
            $created = [];

            foreach ($parsed as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $wallet->refresh();
                $lineGross = (float) $line['line_gross'];
                $share = $grandPreDiscount > 0 ? $discount * ($lineGross / $grandPreDiscount) : 0.0;
                $charged = round(max(0.0, $lineGross - $share), 2);

                $subtotal = (float) $line['subtotal'];
                $deliveryFee = (float) $line['delivery_fee'];
                if ($lineGross > 0 && $share > 0) {
                    $subtotal = round(max(0.0, $subtotal - $share * ($subtotal / $lineGross)), 2);
                    $deliveryFee = round(max(0.0, $deliveryFee - $share * ($deliveryFee / $lineGross)), 2);
                }

                $balanceBefore = $wallet->balance;
                $wallet->decrement('balance', $charged);
                $wallet->refresh();

                $transaction = $wallet->transactions()->create([
                    'transaction_id' => WalletTransaction::generateId(),
                    'user_id' => $buyerId,
                    'type' => 'payment',
                    'amount' => $charged,
                    'fee' => 0,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $wallet->balance,
                    'status' => 'completed',
                    'description' => 'Shop checkout: '.$product->title,
                    'completed_at' => now(),
                ]);

                $order = Order::create([
                    'order_number' => Order::generateOrderNumber(),
                    'buyer_id' => $buyerId,
                    'seller_id' => $product->user_id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'total_amount' => $charged,
                    'currency' => 'TZS',
                    'status' => Order::STATUS_PENDING,
                    'delivery_method' => $line['delivery_method'],
                    'delivery_address' => $line['delivery_address'],
                    'delivery_notes' => $line['delivery_notes'],
                    'wallet_transaction_id' => $transaction->id,
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                ]);

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => Order::STATUS_PENDING,
                    'notes' => 'Order placed (checkout)',
                    'changed_by' => $buyerId,
                ]);

                $product->increment('orders_count');

                $order->load([
                    'product' => fn ($q) => $q->select('id', 'title', 'slug', 'thumbnail_url', 'images', 'price', 'type', 'condition', 'currency', 'category_id')
                        ->with('category:id,name,slug'),
                    'buyer:id,first_name,last_name,username,profile_photo_path',
                    'sellerProfile:id,first_name,last_name,username,profile_photo_path',
                ]);

                $created[] = $this->formatOrder($order);
            }

            $cart = ShoppingCart::where('user_id', $buyerId)->first();
            if ($cart) {
                CartItem::where('cart_id', $cart->id)->delete();
            }

            return $created;
        });

        return response()->json([
            'success' => true,
            'message' => 'Checkout completed',
            'data' => $ordersOut,
        ], 201);
    }

    /**
     * GET /v1/shop/orders/buyer
     */
    public function buyerOrders(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'string|in:pending,confirmed,processing,shipped,delivered,completed,cancelled,refunded',
        ]);

        $perPage = min($request->input('per_page', 20), 50);

        $query = Order::where('buyer_id', $request->user_id)
            ->with([
                'product:id,title,thumbnail_url,price,type,category_id',
                'product.category:id,name,slug',
                'sellerProfile:id,first_name,last_name,username,profile_photo_path',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $orders->map(fn ($o) => $this->formatOrder($o)),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
                    'last_page' => $orders->lastPage(),
                    'has_more' => $orders->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * GET /v1/shop/orders/seller
     */
    public function sellerOrders(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'string|in:pending,confirmed,processing,shipped,delivered,completed,cancelled,refunded',
        ]);

        $perPage = min($request->input('per_page', 20), 50);

        $query = Order::where('seller_id', $request->user_id)
            ->with([
                'product:id,title,thumbnail_url,price,type,category_id',
                'product.category:id,name,slug',
                'buyer:id,first_name,last_name,username,profile_photo_path',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $orders->map(fn ($o) => $this->formatOrder($o)),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
                    'last_page' => $orders->lastPage(),
                    'has_more' => $orders->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * GET /v1/shop/orders/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $order = Order::with([
            'product' => fn ($q) => $q->select('id', 'title', 'slug', 'thumbnail_url', 'images', 'price', 'type', 'condition', 'currency', 'category_id', 'description')
                ->with('category:id,name,slug'),
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
            'statusHistory' => fn ($q) => $q->with('changedByUser:id,first_name,last_name'),
        ])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $order->isParticipant($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOrder($order),
        ]);
    }

    /**
     * GET /shop/orders/{id}/tracking — shipment + status timeline.
     */
    public function tracking(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

        $order = Order::with([
            'statusHistory' => fn ($q) => $q->with('changedByUser:id,first_name,last_name,username')->orderBy('created_at'),
            'buyer:id,first_name,last_name,username',
            'sellerProfile:id,first_name,last_name,username',
        ])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (! $order->isParticipant($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $timeline = $order->statusHistory->map(fn ($h) => [
            'status' => $h->status,
            'notes' => $h->notes,
            'at' => $h->created_at?->toIso8601String(),
            'changed_by' => $h->changedByUser ? [
                'id' => $h->changedByUser->id,
                'name' => trim(($h->changedByUser->first_name ?? '').' '.($h->changedByUser->last_name ?? '')),
                'username' => $h->changedByUser->username,
            ] : null,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'delivery_method' => $order->delivery_method,
                'tracking_number' => $order->tracking_number,
                'estimated_delivery' => $order->estimated_delivery?->toIso8601String(),
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * PUT /v1/shop/orders/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (! $request->has('user_id')) {
            if ($request->has('seller_id')) {
                $request->merge(['user_id' => $request->input('seller_id')]);
            } elseif ($request->has('buyer_id')) {
                $request->merge(['user_id' => $request->input('buyer_id')]);
            }
        }

        if (! $request->has('notes') && $request->has('note')) {
            $request->merge(['notes' => $request->input('note')]);
        }

        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'required|string|in:confirmed,processing,shipped,delivered,completed',
            'notes' => 'nullable|string|max:500',
            'tracking_number' => 'nullable|string|max:100',
            'estimated_delivery' => 'nullable|date',
        ]);

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $userId = (int) $request->user_id;
        $newStatus = $request->status;

        if (in_array($newStatus, ['confirmed', 'processing', 'shipped'], true) && ! $order->isSeller($userId)) {
            return response()->json(['success' => false, 'message' => 'Only seller can update to this status'], 403);
        }

        if (in_array($newStatus, ['delivered', 'completed'], true) && ! $order->isBuyer($userId)) {
            return response()->json(['success' => false, 'message' => 'Only buyer can confirm delivery/completion'], 403);
        }

        if (! $order->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$order->status}' to '{$newStatus}'",
            ], 422);
        }

        DB::transaction(function () use ($order, $request, $newStatus, $userId) {
            $updateData = ['status' => $newStatus];

            if ($newStatus === 'confirmed') {
                $product = $order->product;
                if ($product && $product->hasStock($order->quantity)) {
                    $product->decrement('stock_quantity', $order->quantity);
                    if ($product->fresh()->stock_quantity <= 0) {
                        $product->update(['status' => 'sold_out']);
                    }
                }
            }

            if ($request->filled('tracking_number')) {
                $updateData['tracking_number'] = $request->tracking_number;
            }
            if ($request->filled('estimated_delivery')) {
                $updateData['estimated_delivery'] = $request->estimated_delivery;
            }

            $order->update($updateData);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $newStatus,
                'notes' => $request->input('notes'),
                'changed_by' => $userId,
            ]);
        });

        $order->refresh()->load([
            'product:id,title,thumbnail_url,price,type,category_id',
            'product.category:id,name,slug',
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
            'data' => $this->formatOrder($order),
        ]);
    }

    /**
     * POST /orders/{id}/received — buyer: shipped → delivered
     */
    public function confirmReceived(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);
        $request->merge(['status' => 'delivered']);

        return $this->updateStatus($request, $id);
    }

    /**
     * POST /orders/{id}/return — buyer on delivered → refunded (+ wallet refund, restock if stock was deducted)
     */
    public function requestReturn(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'reason' => 'required|string|max:500',
        ]);

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $userId = (int) $request->user_id;

        if (! $order->isBuyer($userId)) {
            return response()->json(['success' => false, 'message' => 'Only the buyer can request a return'], 403);
        }

        if ($order->status !== Order::STATUS_DELIVERED) {
            return response()->json([
                'success' => false,
                'message' => 'Returns are only allowed for delivered orders',
            ], 422);
        }

        if (! $order->canTransitionTo(Order::STATUS_REFUNDED)) {
            return response()->json(['success' => false, 'message' => 'This order cannot be refunded'], 422);
        }

        DB::transaction(function () use ($order, $request, $userId) {
            $order->update([
                'status' => Order::STATUS_REFUNDED,
                'cancellation_reason' => $request->reason,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => Order::STATUS_REFUNDED,
                'notes' => 'Return/refund: '.$request->reason,
                'changed_by' => $userId,
            ]);

            if ($order->payment_status === 'paid') {
                $buyerWallet = Wallet::getOrCreate($order->buyer_id);
                $balanceBefore = $buyerWallet->balance;
                $buyerWallet->increment('balance', $order->total_amount);
                $buyerWallet->refresh();

                $buyerWallet->transactions()->create([
                    'transaction_id' => WalletTransaction::generateId(),
                    'user_id' => $order->buyer_id,
                    'type' => 'refund',
                    'amount' => $order->total_amount,
                    'fee' => 0,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $buyerWallet->balance,
                    'status' => 'completed',
                    'description' => 'Shop return: Order #'.$order->order_number,
                    'completed_at' => now(),
                ]);

                $order->update(['payment_status' => 'refunded']);
            }

            // Restock if inventory was reduced at confirmation.
            $wasConfirmedFlow = OrderStatusHistory::where('order_id', $order->id)
                ->where('status', Order::STATUS_CONFIRMED)
                ->exists();

            if ($wasConfirmedFlow) {
                $product = $order->product;
                if ($product) {
                    $product->increment('stock_quantity', $order->quantity);
                    if ($product->status === 'sold_out' && $product->fresh()->stock_quantity > 0) {
                        $product->update(['status' => 'active']);
                    }
                }
            }
        });

        $order->refresh()->load([
            'product:id,title,thumbnail_url,price,type,category_id',
            'product.category:id,name,slug',
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Return processed and buyer refunded',
            'data' => $this->formatOrder($order),
        ]);
    }

    /**
     * POST /v1/shop/orders/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $userId = (int) $request->user_id;

        if (! $order->canBeCancelledBy($userId)) {
            $role = $order->isBuyer($userId) ? 'Buyer' : ($order->isSeller($userId) ? 'Seller' : 'User');

            return response()->json([
                'success' => false,
                'message' => "{$role} cannot cancel order in '{$order->status}' status",
            ], 422);
        }

        $previousStatus = $order->status;

        DB::transaction(function () use ($order, $request, $userId, $previousStatus) {
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancellation_reason' => $request->input('reason'),
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => Order::STATUS_CANCELLED,
                'notes' => $request->input('reason', 'Order cancelled'),
                'changed_by' => $userId,
            ]);

            if ($order->payment_status === 'paid') {
                $buyerWallet = Wallet::getOrCreate($order->buyer_id);
                $balanceBefore = $buyerWallet->balance;
                $buyerWallet->increment('balance', $order->total_amount);
                $buyerWallet->refresh();

                $buyerWallet->transactions()->create([
                    'transaction_id' => WalletTransaction::generateId(),
                    'user_id' => $order->buyer_id,
                    'type' => 'refund',
                    'amount' => $order->total_amount,
                    'fee' => 0,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $buyerWallet->balance,
                    'status' => 'completed',
                    'description' => 'Shop refund: Order #'.$order->order_number,
                    'completed_at' => now(),
                ]);

                $order->update(['payment_status' => 'refunded']);
            }

            if (in_array($previousStatus, ['confirmed', 'processing', 'shipped'], true)) {
                $product = $order->product;
                if ($product) {
                    $product->increment('stock_quantity', $order->quantity);
                    if ($product->status === 'sold_out' && $product->fresh()->stock_quantity > 0) {
                        $product->update(['status' => 'active']);
                    }
                }
            }
        });

        $order->refresh()->load([
            'product:id,title,thumbnail_url,price,type,category_id',
            'product.category:id,name,slug',
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled and refund processed',
            'data' => $this->formatOrder($order),
        ]);
    }

    private function formatOrder(Order $order): array
    {
        $data = $order->toArray();

        if ($order->relationLoaded('buyer') && $order->buyer) {
            $data['buyer'] = [
                'id' => $order->buyer->id,
                'first_name' => $order->buyer->first_name,
                'last_name' => $order->buyer->last_name,
                'username' => $order->buyer->username,
                'profile_photo_path' => $order->buyer->profile_photo_path,
            ];
        }

        if ($order->relationLoaded('sellerProfile') && $order->sellerProfile) {
            $data['seller'] = [
                'id' => $order->sellerProfile->id,
                'first_name' => $order->sellerProfile->first_name,
                'last_name' => $order->sellerProfile->last_name,
                'username' => $order->sellerProfile->username,
                'profile_photo_path' => $order->sellerProfile->profile_photo_path,
            ];
        }

        if ($order->relationLoaded('statusHistory')) {
            $data['status_history'] = $order->statusHistory->map(fn ($h) => [
                'id' => $h->id,
                'status' => $h->status,
                'note' => $h->notes,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values()->all();
        }

        unset($data['deleted_at'], $data['seller_profile']);

        return $data;
    }
}
