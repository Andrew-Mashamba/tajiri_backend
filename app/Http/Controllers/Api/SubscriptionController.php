<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use App\Models\Subscription;
use App\Models\CreatorTip;
use App\Models\CreatorEarning;
use App\Models\CreatorPayout;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    /**
     * Get creator's subscription tiers.
     */
    public function getTiers(int $creatorId): JsonResponse
    {
        $tiers = SubscriptionTier::where('creator_id', $creatorId)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tiers,
        ]);
    }

    /**
     * Create a subscription tier.
     */
    public function createTier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'creator_id' => 'required|exists:user_profiles,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:1000',
            'billing_period' => 'in:monthly,yearly',
            'benefits' => 'nullable|array',
        ]);

        $tier = SubscriptionTier::create($validated);

        return response()->json([
            'success' => true,
            'data' => $tier,
        ], 201);
    }

    /**
     * Update a subscription tier.
     */
    public function updateTier(Request $request, int $id): JsonResponse
    {
        $tier = SubscriptionTier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:500',
            'price' => 'numeric|min:1000',
            'benefits' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $tier->update($validated);

        return response()->json([
            'success' => true,
            'data' => $tier,
        ]);
    }

    /**
     * Subscribe to a creator.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|exists:user_profiles,id',
            'tier_id' => 'required|exists:subscription_tiers,id',
            'payment_method' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $tier = SubscriptionTier::findOrFail($validated['tier_id']);

        // Check if already subscribed
        $existing = Subscription::where('subscriber_id', $validated['subscriber_id'])
            ->where('creator_id', $tier->creator_id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Tayari umejisajili',
            ], 400);
        }

        $period = $tier->billing_period === 'yearly' ? 1 : 0;
        $months = $tier->billing_period === 'yearly' ? 0 : 1;

        $subscription = Subscription::create([
            'subscriber_id' => $validated['subscriber_id'],
            'creator_id' => $tier->creator_id,
            'tier_id' => $tier->id,
            'status' => 'active',
            'amount_paid' => $tier->price,
            'started_at' => now(),
            'expires_at' => now()->addYears($period)->addMonths($months),
            'payment_method' => $validated['payment_method'],
            'transaction_id' => $validated['transaction_id'] ?? null,
        ]);

        // Update subscriber count
        $tier->incrementSubscribers();

        // Create earning record
        CreatorEarning::createFromSubscription($subscription);

        return response()->json([
            'success' => true,
            'data' => $subscription->load('tier', 'creator'),
        ], 201);
    }

    /**
     * Get user's subscriptions.
     */
    public function mySubscriptions(int $userId): JsonResponse
    {
        $subscriptions = Subscription::where('subscriber_id', $userId)
            ->with(['creator', 'tier'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subscriptions,
        ]);
    }

    /**
     * Get creator's subscribers.
     */
    public function getSubscribers(int $creatorId): JsonResponse
    {
        $subscriptions = Subscription::where('creator_id', $creatorId)
            ->where('status', 'active')
            ->with(['subscriber', 'tier'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subscriptions,
        ]);
    }

    /**
     * Cancel subscription.
     */
    public function cancel(int $id): JsonResponse
    {
        $subscription = Subscription::findOrFail($id);
        $subscription->cancel();

        return response()->json([
            'success' => true,
            'message' => 'Usajili umesimamishwa',
        ]);
    }

    /**
     * Check if user is subscribed to creator.
     */
    public function checkSubscription(int $subscriberId, int $creatorId): JsonResponse
    {
        $subscription = Subscription::where('subscriber_id', $subscriberId)
            ->where('creator_id', $creatorId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->with('tier')
            ->first();

        return response()->json([
            'success' => true,
            'is_subscribed' => $subscription !== null,
            'data' => $subscription,
        ]);
    }

    /**
     * Send tip to creator.
     */
    public function sendTip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sender_id' => 'required|exists:user_profiles,id',
            'creator_id' => 'required|exists:user_profiles,id',
            'amount' => 'required|numeric|min:500',
            'message' => 'nullable|string|max:200',
            'payment_method' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $tip = CreatorTip::create($validated);

        // Create earning record
        CreatorEarning::createFromTip($tip);

        return response()->json([
            'success' => true,
            'data' => $tip->load('sender', 'creator'),
        ], 201);
    }

    /**
     * Get creator earnings.
     */
    public function getEarnings(int $creatorId): JsonResponse
    {
        $earnings = CreatorEarning::where('creator_id', $creatorId)
            ->orderByDesc('created_at')
            ->paginate(20);

        $summary = [
            'total_gross' => CreatorEarning::where('creator_id', $creatorId)->sum('gross_amount'),
            'total_net' => CreatorEarning::where('creator_id', $creatorId)->sum('net_amount'),
            'pending' => CreatorEarning::where('creator_id', $creatorId)
                ->where('status', 'pending')
                ->sum('net_amount'),
            'this_month' => CreatorEarning::where('creator_id', $creatorId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('net_amount'),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data' => $earnings,
        ]);
    }

    /**
     * Request payout.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'creator_id' => 'required|exists:user_profiles,id',
            'amount' => 'required|numeric|min:10000',
            'payment_method' => 'required|in:mobile_money,bank_transfer',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'provider' => 'nullable|string',
        ]);

        // Check available balance
        $available = CreatorEarning::where('creator_id', $validated['creator_id'])
            ->where('status', 'pending')
            ->sum('net_amount');

        if ($validated['amount'] > $available) {
            return response()->json([
                'success' => false,
                'message' => 'Kiasi kikubwa kuliko salio lako',
            ], 400);
        }

        $payout = CreatorPayout::create($validated);

        return response()->json([
            'success' => true,
            'data' => $payout,
        ], 201);
    }

    /**
     * Get payout history.
     */
    public function getPayouts(int $creatorId): JsonResponse
    {
        $payouts = CreatorPayout::where('creator_id', $creatorId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payouts,
        ]);
    }
}
