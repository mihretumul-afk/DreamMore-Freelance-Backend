<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EscrowTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends BaseApiController
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Show authenticated user's wallet balance.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $this->walletService->walletFor($user);

        return $this->sendResponse([
            'id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'available_balance' => (float) $wallet->available_balance,
            'pending_balance' => (float) $wallet->pending_balance,
            'currency' => $wallet->currency,
            'updated_at' => $wallet->updated_at,
        ], 'Wallet balance retrieved successfully.');
    }

    /**
     * Withdraw available funds (Test Mode).
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string|in:ETB',
        ]);

        $user = $request->user();

        try {
            $transaction = $this->walletService->withdraw(
                $user,
                (float) $validated['amount'],
                $validated['currency'] ?? 'ETB'
            );

            return $this->sendResponse([
                'transaction' => $transaction,
                'message' => 'Withdrawal completed in Test Mode.',
            ], 'Withdrawal successful.');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Get transaction history for current user.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = EscrowTransaction::with(['contract', 'milestone'])
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)
                  ->orWhere('to_user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        return $this->sendResponse($transactions->items(), 'Transactions retrieved successfully.', 200, [
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
            'per_page' => $transactions->perPage(),
            'total' => $transactions->total(),
        ]);
    }
}
