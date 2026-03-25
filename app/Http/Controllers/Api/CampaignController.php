<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignDonation;
use App\Models\CampaignWithdrawal;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $category = $request->query('category');
        $search = $request->query('q');
        $sortBy = $request->query('sort_by', 'recent'); // recent, popular, ending_soon, most_funded

        $query = Campaign::with('user:id,first_name,last_name,username,profile_photo_path')
            ->active();

        if ($category) {
            $query->byCategory($category);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', '%' . $search . '%')
                    ->orWhere('short_description', 'ilike', '%' . $search . '%');
            });
        }

        switch ($sortBy) {
            case 'popular':
                $query->orderByDesc('donors_count');
                break;
            case 'ending_soon':
                $query->whereNotNull('deadline')
                    ->where('deadline', '>', now())
                    ->orderBy('deadline');
                break;
            case 'most_funded':
                $query->orderByDesc('raised_amount');
                break;
            default:
                $query->orderByDesc('created_at');
        }

        $campaigns = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($campaigns->items())->map(function ($campaign) {
            $item = $campaign->toArray();
            $item['progress_percent'] = $campaign->progress_percent;
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
            'title' => 'required|string|max:255',
            'story' => 'required|string',
            'short_description' => 'nullable|string|max:500',
            'goal_amount' => 'required|numeric|min:1000',
            'currency' => 'nullable|string|max:10',
            'category' => 'required|string|in:' . implode(',', Campaign::CATEGORIES),
            'deadline' => 'nullable|date|after:today',
            'cover_image' => 'nullable|image|max:10240',
            'allow_anonymous_donations' => 'nullable|boolean',
            'minimum_donation' => 'nullable|numeric|min:100',
            'is_urgent' => 'nullable|boolean',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'mobile_money_number' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('cover_image')) {
            $data['cover_image_path'] = $request->file('cover_image')
                ->store('campaigns/covers', 'public');
        }

        $data['status'] = Campaign::STATUS_DRAFT;
        $data['currency'] = $data['currency'] ?? 'TZS';

        $campaign = Campaign::create($data);
        $campaign->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully',
            'data' => array_merge($campaign->toArray(), [
                'progress_percent' => $campaign->progress_percent,
            ]),
        ], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $campaign = Campaign::with('user:id,first_name,last_name,username,profile_photo_path')
            ->find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        $campaign->increment('views_count');

        $recentDonations = $campaign->completedDonations()
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->map(function ($donation) {
                if ($donation->is_anonymous) {
                    $donation->donor_name = 'Anonymous';
                    $donation->donor_avatar_url = null;
                    $donation->donor_id = null;
                }
                return $donation;
            });

        $data = $campaign->toArray();
        $data['progress_percent'] = $campaign->progress_percent;
        $data['recent_donations'] = $recentDonations;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($campaign->user_id !== (int) $request->input('user_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if (!in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_COMPLETED])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or completed campaigns can be deleted',
            ], 400);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }

    public function userCampaigns(int $userId, Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $currentUserId = $request->query('current_user_id');
        $status = $request->query('status');

        $query = Campaign::with('user:id,first_name,last_name,username,profile_photo_path')
            ->where('user_id', $userId);

        // If viewing own campaigns, show all statuses; otherwise only active
        if ((int) $currentUserId !== $userId) {
            $query->active();
        } elseif ($status) {
            $query->where('status', $status);
        }

        $campaigns = $query->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($campaigns->items())->map(function ($campaign) {
            $item = $campaign->toArray();
            $item['progress_percent'] = $campaign->progress_percent;
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function userCampaignStats(int $userId): JsonResponse
    {
        $campaigns = Campaign::where('user_id', $userId)->get();

        $totalRaised = $campaigns->sum('raised_amount');
        $totalDonors = $campaigns->sum('donors_count');

        $withdrawnOrPending = CampaignWithdrawal::whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereNotIn('status', ['rejected', 'failed'])
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_campaigns' => $campaigns->count(),
                'active_campaigns' => $campaigns->where('status', Campaign::STATUS_ACTIVE)->count(),
                'total_raised' => round($totalRaised, 2),
                'total_donors' => $totalDonors,
                'available_balance' => round(max(0, $totalRaised - $withdrawnOrPending), 2),
                'currency' => 'TZS',
            ],
        ]);
    }

    public function publish(int $id, Request $request): JsonResponse
    {
        return $this->changeStatus($id, $request, Campaign::STATUS_DRAFT, Campaign::STATUS_ACTIVE, 'Campaign published successfully');
    }

    public function pause(int $id, Request $request): JsonResponse
    {
        return $this->changeStatus($id, $request, Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, 'Campaign paused successfully');
    }

    public function resume(int $id, Request $request): JsonResponse
    {
        return $this->changeStatus($id, $request, Campaign::STATUS_PAUSED, Campaign::STATUS_ACTIVE, 'Campaign resumed successfully');
    }

    public function complete(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($campaign->user_id !== (int) $request->input('user_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if (!in_array($campaign->status, [Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED])) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign cannot be completed from current status',
            ], 400);
        }

        $campaign->update(['status' => Campaign::STATUS_COMPLETED]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign completed successfully',
            'data' => array_merge($campaign->fresh()->toArray(), [
                'progress_percent' => $campaign->fresh()->progress_percent,
            ]),
        ]);
    }

    public function donate(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'donor_id' => 'nullable|integer|exists:user_profiles,id',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:wallet,mobile_money',
            'is_anonymous' => 'nullable|boolean',
            'message' => 'nullable|string|max:500',
            'donor_name' => 'nullable|string|max:255',
            'pin' => 'nullable|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($campaign->status !== Campaign::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign is not accepting donations',
            ], 400);
        }

        $amount = (float) $request->input('amount');

        if ($campaign->minimum_donation && $amount < $campaign->minimum_donation) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum donation amount is ' . number_format($campaign->minimum_donation) . ' ' . $campaign->currency,
            ], 400);
        }

        $paymentMethod = $request->input('payment_method');
        $donorId = $request->input('donor_id');
        $isAnonymous = $request->boolean('is_anonymous', false);

        // Get donor info
        $donorName = $request->input('donor_name', 'Anonymous');
        $donorAvatarUrl = null;
        if ($donorId && !$isAnonymous) {
            $donor = \App\Models\UserProfile::find($donorId);
            if ($donor) {
                $donorName = $donor->first_name . ' ' . $donor->last_name;
                $donorAvatarUrl = $donor->profile_photo_path;
            }
        }

        DB::beginTransaction();
        try {
            $donationData = [
                'campaign_id' => $campaign->id,
                'donor_id' => $donorId,
                'amount' => $amount,
                'currency' => $campaign->currency,
                'is_anonymous' => $isAnonymous,
                'message' => $request->input('message'),
                'donor_name' => $isAnonymous ? 'Anonymous' : $donorName,
                'donor_avatar_url' => $isAnonymous ? null : $donorAvatarUrl,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
            ];

            if ($paymentMethod === 'wallet') {
                if (!$donorId) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'donor_id is required for wallet payments',
                    ], 422);
                }

                $pin = $request->input('pin');
                if (!$pin) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN is required for wallet payments',
                    ], 422);
                }

                $wallet = Wallet::getOrCreate($donorId);

                if (!$wallet->verifyPin($pin)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN si sahihi',
                    ], 400);
                }

                if (!$wallet->canAfford($amount)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Salio halitoshi',
                    ], 400);
                }

                $transaction = $wallet->debit($amount, 0, 'Michango: ' . $campaign->title);

                if (!$transaction) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment failed',
                    ], 400);
                }

                $donationData['payment_ref'] = $transaction->transaction_id;
                $donationData['status'] = 'completed';
                $donationData['completed_at'] = now();

                // Update campaign totals
                $campaign->increment('raised_amount', $amount);
                $campaign->increment('donors_count');
            }

            // For mobile_money, donation stays pending until payment callback
            $donation = CampaignDonation::create($donationData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $paymentMethod === 'wallet'
                    ? 'Donation completed successfully'
                    : 'Donation initiated, awaiting payment confirmation',
                'data' => $donation,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Donation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function listWithdrawals(int $id, Request $request): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        $userId = $request->query('user_id');
        if ($campaign->user_id !== (int) $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $withdrawals = $campaign->withdrawals()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $withdrawals->items(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
                'available_balance' => $campaign->available_balance,
            ],
        ]);
    }

    public function requestWithdrawal(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
            'amount' => 'required|numeric|min:1000',
            'destination_type' => 'required|string|in:bank,mobile_money',
            'destination_details' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($campaign->user_id !== (int) $request->input('user_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $amount = (float) $request->input('amount');
        $availableBalance = $campaign->available_balance;

        if ($amount > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal amount exceeds available balance of ' . number_format($availableBalance) . ' ' . $campaign->currency,
            ], 400);
        }

        $withdrawal = CampaignWithdrawal::create([
            'campaign_id' => $campaign->id,
            'amount' => $amount,
            'currency' => $campaign->currency,
            'status' => 'pending',
            'destination_type' => $request->input('destination_type'),
            'destination_details' => $request->input('destination_details'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully',
            'data' => $withdrawal,
        ], 201);
    }

    public function userWithdrawals(int $userId, Request $request): JsonResponse
    {
        $campaignIds = Campaign::where('user_id', $userId)->pluck('id');

        $withdrawals = CampaignWithdrawal::whereIn('campaign_id', $campaignIds)
            ->with('campaign:id,title')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $withdrawals->items(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    private function changeStatus(int $id, Request $request, string $fromStatus, string $toStatus, string $message): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign = Campaign::find($id);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($campaign->user_id !== (int) $request->input('user_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($campaign->status !== $fromStatus) {
            return response()->json([
                'success' => false,
                'message' => "Campaign must be in '{$fromStatus}' status",
            ], 400);
        }

        $campaign->update(['status' => $toStatus]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => array_merge($campaign->fresh()->toArray(), [
                'progress_percent' => $campaign->fresh()->progress_percent,
            ]),
        ]);
    }
}
