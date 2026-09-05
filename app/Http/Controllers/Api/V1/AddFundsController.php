<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Services\Payment\AddFundsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddFundsController extends BaseApiController
{
    /**
     * Initiate a wallet deposit (Add Funds).
     *
     * POST /api/v1/wallet/deposit
     *
     * @bodyParam amount float required Amount to deposit (min 10, max 500000)
     * @bodyParam payment_method_id int required Payment method ID
     */
    public function initiate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['employer', 'freelancer'])) {
            return $this->sendForbidden('Only employers and freelancers can add funds.');
        }

        $validated = $request->validate([
            'amount'            => 'required|numeric|min:10|max:500000',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
        ]);

        // Verify payment method belongs to the user
        $paymentMethod = $user->paymentMethods()->where('id', $validated['payment_method_id'])->first();
        if (!$paymentMethod) {
            return $this->sendForbidden('This payment method does not belong to you.');
        }

        try {
            $service = app(AddFundsService::class);
            $result = $service->initiate(
                $user->id,
                (float) $validated['amount'],
                $validated['payment_method_id']
            );

            $payment = $result['payment'];
            $wallet = $result['wallet'];

            $response = [
                'payment' => [
                    'id'                 => $payment->id,
                    'reference'          => $payment->reference,
                    'amount'             => (float) $payment->amount,
                    'status'             => $payment->status,
                    'provider'           => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'processed_at'       => $payment->processed_at?->toIso8601String(),
                    'created_at'         => $payment->created_at?->toIso8601String(),
                ],
                'wallet' => [
                    'available_balance' => (float) $wallet->available_balance,
                    'currency'          => $wallet->currency,
                ],
                'previous_balance' => $result['previous_balance'],
            ];

            // Include checkout_url for redirect-based providers (e.g., Chapa)
            if (!empty($result['checkout_url'])) {
                $response['checkout_url'] = $result['checkout_url'];
                $response['payment']['status'] = 'pending';
            }

            $message = !empty($result['checkout_url'])
                ? 'Redirect to checkout to complete payment.'
                : 'Funds added successfully.';

            return $this->sendResponse($response, $message, 200);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Get deposit status (for polling after initiation).
     *
     * GET /api/v1/wallet/deposit/{payment}
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();

        if ($payment->payer_id !== $user->id) {
            return $this->sendForbidden('Access denied.');
        }

        if ($payment->type !== Payment::TYPE_WALLET_DEPOSIT) {
            return $this->sendError('Invalid payment type.', [], 422);
        }

        try {
            $service = app(AddFundsService::class);
            $status = $service->getDepositStatus($payment, $user->id);

            return $this->sendResponse($status, 'Deposit status retrieved.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Handle payment provider webhook for deposit confirmation.
     *
     * POST /api/v1/webhooks/deposit/{provider}
     *
     * This endpoint is NOT protected by auth (provider calls it directly).
     * Signature verification happens inside the service.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Webhook-Signature', '');

        try {
            $service = app(AddFundsService::class);
            $result = $service->handleWebhook($provider, $payload, $signature);

            // The transaction reference doesn't belong to a user wallet deposit —
            // it may be an admin platform deposit (admin Add Funds via Chapa).
            if (!$result['success'] && ($result['error'] ?? '') === 'Payment not found') {
                $platformService = app(\App\Services\Payment\PlatformFinanceService::class);
                $result = $platformService->handleWebhook($provider, $payload, $signature);
            }

            if ($result['success']) {
                return response()->json(['received' => true]);
            }

            return response()->json(['error' => $result['error'] ?? 'Webhook processing failed'], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[Add Funds] Webhook error', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Reconcile a specific deposit by reference.
     *
     * POST /api/v1/wallet/deposit/reconcile/{reference}
     *
     * Called by the frontend after returning from Chapa checkout.
     * Polls Chapa API and credits wallet if payment is confirmed.
     */
    public function reconcile(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $payment = \App\Models\Payment::where('reference', $reference)
            ->where('payer_id', $user->id)
            ->where('type', \App\Models\Payment::TYPE_WALLET_DEPOSIT)
            ->first();

        if (!$payment) {
            return $this->sendError('Payment not found.', [], 404);
        }

        try {
            $service = app(AddFundsService::class);
            $status = $service->getDepositStatus($payment, $user->id);

            return $this->sendResponse($status, 'Deposit status retrieved.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Reconcile ALL pending deposits for the current user.
     *
     * POST /api/v1/wallet/deposit/reconcile-all
     *
     * Called by the frontend on page load to ensure any completed
     * but uncredited deposits get processed.
     */
    public function reconcileAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $service = app(AddFundsService::class);
        $reconciled = 0;

        // Only check payments older than 2 minutes (recent ones are still processing)
        $pendingPayments = \App\Models\Payment::where('payer_id', $user->id)
            ->where('type', \App\Models\Payment::TYPE_WALLET_DEPOSIT)
            ->where('status', \App\Models\Payment::STATUS_PENDING)
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->limit(3)  // Max 3 at a time to prevent timeouts
            ->get();

        if ($pendingPayments->isEmpty()) {
            return $this->sendResponse(['reconciled' => 0, 'pending' => 0], 'No pending deposits.');
        }

        foreach ($pendingPayments as $payment) {
            try {
                // Skip payments older than 30 minutes — likely never completed
                if ($payment->created_at->diffInMinutes(now()) > 30) {
                    $payment->update([
                        'status'         => \App\Models\Payment::STATUS_FAILED,
                        'failure_reason' => 'Payment expired — not completed within 30 minutes',
                    ]);
                    continue;
                }

                $service->getDepositStatus($payment, $user->id);
                $payment->refresh();
                if ($payment->status === \App\Models\Payment::STATUS_COMPLETED) {
                    $reconciled++;
                }
            } catch (\Exception $e) {
                // Continue with next payment
            }
        }

        return $this->sendResponse([
            'reconciled' => $reconciled,
            'pending'    => $pendingPayments->count() - $reconciled,
        ], 'Reconciliation complete.');
    }
}
