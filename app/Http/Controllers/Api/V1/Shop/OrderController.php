<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\OrderStatusHistory;
use App\Models\Shop\Product;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
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
        ]);

        $buyerId = $request->input('user_id');
        $product = Product::find($request->product_id);

        if (!$product || !$product->isAvailable()) {
            return response()->json(['success' => false, 'message' => 'Product is not available'], 422);
        }

        if ($product->user_id == $buyerId) {
            return response()->json(['success' => false, 'message' => 'Cannot buy your own product'], 422);
        }

        if (!$product->hasStock($request->quantity)) {
            return response()->json(['success' => false, 'message' => 'Insufficient stock. Available: ' . $product->stock_quantity], 422);
        }

        // Digital products must use digital delivery
        if ($product->type === 'digital' && $request->delivery_method !== 'digital') {
            return response()->json(['success' => false, 'message' => 'Digital products must use digital delivery'], 422);
        }

        // Calculate totals
        $unitPrice = $product->price;
        $subtotal = $unitPrice * $request->quantity;
        $deliveryFee = in_array($request->delivery_method, ['delivery', 'shipping']) ? ($product->delivery_fee ?? 0) : 0;
        $totalAmount = $subtotal + $deliveryFee;

        // Verify wallet balance
        $wallet = Wallet::getOrCreate($buyerId);
        if (!$wallet->canAfford($totalAmount)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance. Required: ' . number_format($totalAmount, 2) . ' TZS, Available: ' . number_format($wallet->balance, 2) . ' TZS',
            ], 422);
        }

        // Create order + payment in transaction
        $order = DB::transaction(function () use ($request, $product, $buyerId, $unitPrice, $subtotal, $deliveryFee, $totalAmount, $wallet) {
            // Debit buyer wallet
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
                'description' => 'Shop purchase: ' . $product->title,
                'completed_at' => now(),
            ]);

            // Create the order
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

            // Record status history
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => Order::STATUS_PENDING,
                'notes' => 'Order placed',
                'changed_by' => $buyerId,
            ]);

            // Update product stats
            $product->increment('orders_count');

            return $order;
        });

        $order->load([
            'product:id,title,thumbnail_url,price,type',
            'buyer:id,first_name,last_name,username',
            'sellerProfile:id,first_name,last_name,username',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully',
            'data' => ['order' => $this->formatOrder($order)],
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
                'product:id,title,thumbnail_url,price,type',
                'sellerProfile:id,first_name,last_name,username,profile_photo_path',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $orders->map(fn($o) => $this->formatOrder($o)),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
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
                'product:id,title,thumbnail_url,price,type',
                'buyer:id,first_name,last_name,username,profile_photo_path',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $orders->map(fn($o) => $this->formatOrder($o)),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
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
            'product:id,title,slug,thumbnail_url,images,price,type,condition',
            'buyer:id,first_name,last_name,username,profile_photo_path',
            'sellerProfile:id,first_name,last_name,username,profile_photo_path',
            'statusHistory' => fn($q) => $q->with('changedByUser:id,first_name,last_name'),
        ])->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (!$order->isParticipant($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => ['order' => $this->formatOrder($order)],
        ]);
    }

    /**
     * PUT /v1/shop/orders/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'status' => 'required|string|in:confirmed,processing,shipped,delivered,completed',
            'notes' => 'nullable|string|max:500',
            'tracking_number' => 'nullable|string|max:100',
            'estimated_delivery' => 'nullable|date',
        ]);

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $userId = $request->user_id;
        $newStatus = $request->status;

        // Validate who can set which status
        if (in_array($newStatus, ['confirmed', 'processing', 'shipped']) && !$order->isSeller($userId)) {
            return response()->json(['success' => false, 'message' => 'Only seller can update to this status'], 403);
        }

        if (in_array($newStatus, ['delivered', 'completed']) && !$order->isBuyer($userId)) {
            return response()->json(['success' => false, 'message' => 'Only buyer can confirm delivery/completion'], 403);
        }

        if (!$order->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$order->status}' to '{$newStatus}'",
            ], 422);
        }

        DB::transaction(function () use ($order, $request, $newStatus, $userId) {
            $updateData = ['status' => $newStatus];

            // Decrement stock when confirmed
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
            'product:id,title,thumbnail_url,price,type',
            'buyer:id,first_name,last_name,username',
            'sellerProfile:id,first_name,last_name,username',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
            'data' => ['order' => $this->formatOrder($order)],
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
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        $userId = $request->user_id;

        if (!$order->canBeCancelledBy($userId)) {
            $role = $order->isBuyer($userId) ? 'Buyer' : ($order->isSeller($userId) ? 'Seller' : 'User');
            return response()->json([
                'success' => false,
                'message' => "{$role} cannot cancel order in '{$order->status}' status",
            ], 422);
        }

        DB::transaction(function () use ($order, $request, $userId) {
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

            // Refund buyer's wallet
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
                    'description' => 'Shop refund: Order #' . $order->order_number,
                    'completed_at' => now(),
                ]);

                $order->update(['payment_status' => 'refunded']);
            }

            // Restore stock if it was decremented (only if order was confirmed+)
            if (in_array($order->getOriginal('status'), ['confirmed', 'processing'])) {
                $product = $order->product;
                if ($product) {
                    $product->increment('stock_quantity', $order->quantity);
                    if ($product->status === 'sold_out' && $product->fresh()->stock_quantity > 0) {
                        $product->update(['status' => 'active']);
                    }
                }
            }
        });

        $order->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled and refund processed',
            'data' => ['order' => $this->formatOrder($order)],
        ]);
    }

    private function formatOrder(Order $order): array
    {
        $data = $order->toArray();

        if ($order->relationLoaded('buyer') && $order->buyer) {
            $data['buyer'] = [
                'id' => $order->buyer->id,
                'name' => trim($order->buyer->first_name . ' ' . $order->buyer->last_name),
                'username' => $order->buyer->username,
                'avatar_url' => $order->buyer->profile_photo_path ?? null,
            ];
        }

        if ($order->relationLoaded('sellerProfile') && $order->sellerProfile) {
            $data['seller'] = [
                'id' => $order->sellerProfile->id,
                'name' => trim($order->sellerProfile->first_name . ' ' . $order->sellerProfile->last_name),
                'username' => $order->sellerProfile->username,
                'avatar_url' => $order->sellerProfile->profile_photo_path ?? null,
            ];
        }

        unset($data['deleted_at'], $data['seller_profile']);
        return $data;
    }
}
