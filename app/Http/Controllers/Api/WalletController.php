<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WalletTransfer;
use App\Models\MobileMoneyAccount;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    /**
     * Get user's wallet.
     */
    public function getWallet(int $userId): JsonResponse
    {
        $wallet = Wallet::getOrCreate($userId);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $wallet->balance,
                'pending_balance' => $wallet->pending_balance,
                'currency' => $wallet->currency,
                'is_active' => $wallet->is_active,
                'has_pin' => $wallet->hasPin(),
            ],
        ]);
    }

    /**
     * Set wallet PIN.
     */
    public function setPin(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'pin' => 'required|string|size:4|regex:/^[0-9]+$/',
            'confirm_pin' => 'required|same:pin',
        ]);

        $wallet = Wallet::getOrCreate($userId);

        if ($wallet->hasPin()) {
            return response()->json([
                'success' => false,
                'message' => 'PIN tayari imewekwa. Tumia kubadilisha PIN.',
            ], 400);
        }

        $wallet->setPin($validated['pin']);

        return response()->json([
            'success' => true,
            'message' => 'PIN imewekwa',
        ]);
    }

    /**
     * Change wallet PIN.
     */
    public function changePin(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'current_pin' => 'required|string|size:4',
            'new_pin' => 'required|string|size:4|regex:/^[0-9]+$/',
            'confirm_pin' => 'required|same:new_pin',
        ]);

        $wallet = Wallet::getOrCreate($userId);

        if (!$wallet->verifyPin($validated['current_pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'PIN ya sasa si sahihi',
            ], 400);
        }

        $wallet->setPin($validated['new_pin']);

        return response()->json([
            'success' => true,
            'message' => 'PIN imebadilishwa',
        ]);
    }

    /**
     * Get transaction history.
     */
    public function transactions(int $userId): JsonResponse
    {
        $wallet = Wallet::getOrCreate($userId);

        $transactions = $wallet->transactions()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Deposit via mobile money.
     */
    public function deposit(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000|max:5000000',
            'provider' => 'required|in:mpesa,tigopesa,airtelmoney,halopesa',
            'phone_number' => 'required|string',
            'pin' => 'required|string|size:4',
        ]);

        $wallet = Wallet::getOrCreate($userId);

        if (!$wallet->verifyPin($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'PIN si sahihi',
            ], 400);
        }

        // Create pending transaction
        $transaction = $wallet->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $userId,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'amount' => $validated['amount'],
            'fee' => 0,
            'balance_before' => $wallet->balance,
            'balance_after' => $wallet->balance + $validated['amount'],
            'status' => WalletTransaction::STATUS_PENDING,
            'payment_method' => 'mobile_money',
            'provider' => $validated['provider'],
            'description' => 'Uingizaji via ' . strtoupper($validated['provider']),
        ]);

        // In production, initiate mobile money push here
        // For now, simulate success
        $transaction->complete();
        $wallet->increment('balance', $validated['amount']);

        return response()->json([
            'success' => true,
            'data' => $transaction->fresh(),
            'new_balance' => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Withdraw to mobile money.
     */
    public function withdraw(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000|max:3000000',
            'provider' => 'required|in:mpesa,tigopesa,airtelmoney,halopesa',
            'phone_number' => 'required|string',
            'pin' => 'required|string|size:4',
        ]);

        $wallet = Wallet::getOrCreate($userId);

        if (!$wallet->verifyPin($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'PIN si sahihi',
            ], 400);
        }

        // Calculate fee (1% with min 500 TZS)
        $fee = max(500, $validated['amount'] * 0.01);
        $total = $validated['amount'] + $fee;

        if (!$wallet->canAfford($total)) {
            return response()->json([
                'success' => false,
                'message' => 'Salio halitoshi',
            ], 400);
        }

        // Debit wallet
        $transaction = $wallet->transactions()->create([
            'transaction_id' => WalletTransaction::generateId(),
            'user_id' => $userId,
            'type' => WalletTransaction::TYPE_WITHDRAWAL,
            'amount' => $validated['amount'],
            'fee' => $fee,
            'balance_before' => $wallet->balance,
            'balance_after' => $wallet->balance - $total,
            'status' => WalletTransaction::STATUS_PENDING,
            'payment_method' => 'mobile_money',
            'provider' => $validated['provider'],
            'description' => 'Uondoaji kwenda ' . $validated['phone_number'],
        ]);

        $wallet->decrement('balance', $total);

        // In production, initiate mobile money disbursement here
        // For now, simulate success
        $transaction->complete();

        return response()->json([
            'success' => true,
            'data' => $transaction->fresh(),
            'new_balance' => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Transfer to another user.
     */
    public function transfer(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'recipient_id' => 'required|exists:user_profiles,id',
            'amount' => 'required|numeric|min:100|max:5000000',
            'note' => 'nullable|string|max:200',
            'pin' => 'required|string|size:4',
        ]);

        $wallet = Wallet::getOrCreate($userId);

        if (!$wallet->verifyPin($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'PIN si sahihi',
            ], 400);
        }

        if ($userId == $validated['recipient_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Huwezi kujitumia pesa',
            ], 400);
        }

        $transfer = WalletTransfer::send(
            $userId,
            $validated['recipient_id'],
            $validated['amount'],
            $validated['note']
        );

        if (!$transfer) {
            return response()->json([
                'success' => false,
                'message' => 'Salio halitoshi',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $transfer->load('recipient'),
            'new_balance' => $wallet->fresh()->balance,
        ]);
    }

    // ==================== Mobile Money Accounts ====================

    /**
     * Get linked mobile money accounts.
     */
    public function getMobileAccounts(int $userId): JsonResponse
    {
        $accounts = MobileMoneyAccount::where('user_id', $userId)->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Link mobile money account.
     */
    public function linkMobileAccount(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|in:mpesa,tigopesa,airtelmoney,halopesa',
            'phone_number' => 'required|string',
            'account_name' => 'required|string',
        ]);

        $account = MobileMoneyAccount::create([
            'user_id' => $userId,
            'provider' => $validated['provider'],
            'phone_number' => $validated['phone_number'],
            'account_name' => $validated['account_name'],
            'is_verified' => true, // In production, verify via OTP
            'verified_at' => now(),
        ]);

        // Make primary if first account
        if (MobileMoneyAccount::where('user_id', $userId)->count() === 1) {
            $account->makePrimary();
        }

        return response()->json([
            'success' => true,
            'data' => $account,
        ], 201);
    }

    /**
     * Remove mobile money account.
     */
    public function removeMobileAccount(int $accountId): JsonResponse
    {
        $account = MobileMoneyAccount::findOrFail($accountId);
        $account->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    // ==================== Payment Requests ====================

    /**
     * Request payment from another user.
     */
    public function requestPayment(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'payer_id' => 'required|exists:user_profiles,id',
            'amount' => 'required|numeric|min:100',
            'description' => 'nullable|string|max:200',
        ]);

        $paymentRequest = PaymentRequest::create([
            'requester_id' => $userId,
            'payer_id' => $validated['payer_id'],
            'amount' => $validated['amount'],
            'description' => $validated['description'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $paymentRequest->load('requester', 'payer'),
        ], 201);
    }

    /**
     * Get pending payment requests.
     */
    public function getPaymentRequests(int $userId): JsonResponse
    {
        $incoming = PaymentRequest::where('payer_id', $userId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with('requester')
            ->get();

        $outgoing = PaymentRequest::where('requester_id', $userId)
            ->where('status', 'pending')
            ->with('payer')
            ->get();

        return response()->json([
            'success' => true,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
        ]);
    }

    /**
     * Pay a payment request.
     */
    public function payRequest(Request $request, int $requestId): JsonResponse
    {
        $validated = $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        $paymentRequest = PaymentRequest::findOrFail($requestId);
        $wallet = Wallet::getOrCreate($paymentRequest->payer_id);

        if (!$wallet->verifyPin($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'PIN si sahihi',
            ], 400);
        }

        if (!$paymentRequest->pay()) {
            return response()->json([
                'success' => false,
                'message' => 'Salio halitoshi',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $paymentRequest->fresh()->load('requester'),
            'new_balance' => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Decline a payment request.
     */
    public function declineRequest(int $requestId): JsonResponse
    {
        $paymentRequest = PaymentRequest::findOrFail($requestId);
        $paymentRequest->decline();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Cancel a payment request.
     */
    public function cancelRequest(int $requestId): JsonResponse
    {
        $paymentRequest = PaymentRequest::findOrFail($requestId);
        $paymentRequest->cancel();

        return response()->json([
            'success' => true,
        ]);
    }
}
