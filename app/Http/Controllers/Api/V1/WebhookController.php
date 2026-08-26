<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\Payment\ChapaPaymentProvider;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController — receives payment provider callbacks.
 *
 * Security requirements:
 *   - Verify webhook signature before processing
 *   - Make processing idempotent (prevent duplicate processing)
 *   - Never expose webhook secrets
 *   - Log all webhook events for audit
 */
class WebhookController extends BaseApiController
{
    /**
     * POST /payments/webhook/chapa
     *
     * Handle Chapa payment webhook callbacks.
     *
     * Chapa sends webhooks as POST with JSON body containing:
     *   - event: 'success' | 'failed' | 'cancelled'
     *   - tx_ref: transaction reference
     *   - status: payment status
     *   - amount: paid amount
     *   - currency: currency
     *   - etc.
     */
    public function chapaWebhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!$payload) {
            Log::warning('Chapa webhook: Invalid JSON payload');
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        Log::info('Chapa webhook received', ['event' => $payload['event'] ?? 'unknown', 'tx_ref' => $payload['tx_ref'] ?? 'unknown']);

        // 1. Verify webhook signature
        $signature = $request->header('X-CHAPA-SIGNATURE', '');
        $provider = new ChapaPaymentProvider();

        if (!$provider->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Chapa webhook: Invalid signature', ['tx_ref' => $payload['tx_ref'] ?? 'unknown']);
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        // 2. Extract transaction reference
        $txRef = $payload['tx_ref'] ?? null;
        if (!$txRef) {
            Log::warning('Chapa webhook: Missing tx_ref');
            return response()->json(['status' => 'error', 'message' => 'Missing tx_ref'], 400);
        }

        // 3. Find the payment — idempotent: skip if already processed
        $payment = Payment::where('reference', $txRef)
            ->orWhere('provider_reference', $txRef)
            ->first();

        if (!$payment) {
            Log::warning('Chapa webhook: Payment not found', ['tx_ref' => $txRef]);
            return response()->json(['status' => 'ignored', 'message' => 'Payment not found']);
        }

        // 4. Idempotent check — skip if already completed or refunded
        if (in_array($payment->status, [Payment::STATUS_COMPLETED, Payment::STATUS_REFUNDED], true)) {
            Log::info('Chapa webhook: Payment already processed', ['payment_id' => $payment->id, 'status' => $payment->status]);
            return response()->json(['status' => 'already_processed']);
        }

        // 5. Map webhook status to internal status
        $chapaStatus = $payload['status'] ?? $payload['event'] ?? '';
        $newStatus = $provider->mapWebhookStatus($chapaStatus);

        // 6. Verify amount server-side (never trust frontend)
        $webhookAmount = (float) ($payload['amount'] ?? 0);
        if ($webhookAmount > 0 && abs($webhookAmount - (float) $payment->amount) > 0.01) {
            Log::warning('Chapa webhook: Amount mismatch', [
                'payment_id'     => $payment->id,
                'expected'       => $payment->amount,
                'received'       => $webhookAmount,
                'provider_ref'   => $payload['id'] ?? null,
            ]);
        }

        // 7. Update payment status
        if ($newStatus === Payment::STATUS_COMPLETED) {
            $payment->update([
                'status'             => Payment::STATUS_COMPLETED,
                'provider_reference' => $payload['id'] ?? $txRef,
                'provider_response'  => $payload,
                'processed_at'       => now(),
            ]);

            // Mirror on transactions
            Transaction::where('payment_id', $payment->id)
                ->update(['status' => Payment::STATUS_COMPLETED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_VERIFIED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                [
                    'reference'     => $payment->reference,
                    'provider_ref'  => $payload['id'] ?? null,
                    'action'        => 'webhook_verified',
                ],
                null,
                "Chapa webhook verified payment {$payment->reference}"
            );

        } elseif ($newStatus === Payment::STATUS_FAILED) {
            $payment->update([
                'status'            => Payment::STATUS_FAILED,
                'failed_at'         => now(),
                'failure_reason'    => 'Chapa webhook: ' . $chapaStatus,
                'provider_response' => $payload,
            ]);

            Transaction::where('payment_id', $payment->id)
                ->update(['status' => Payment::STATUS_FAILED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                [
                    'reference' => $payment->reference,
                    'action'    => 'webhook_failed',
                    'reason'    => $chapaStatus,
                ],
                null,
                "Chapa webhook: payment {$payment->reference} failed"
            );

        } elseif ($newStatus === Payment::STATUS_CANCELLED) {
            $payment->update([
                'status'            => Payment::STATUS_CANCELLED,
                'failure_reason'    => 'Chapa webhook: ' . $chapaStatus,
                'provider_response' => $payload,
            ]);

            Transaction::where('payment_id', $payment->id)
                ->update(['status' => Payment::STATUS_CANCELLED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                [
                    'reference' => $payment->reference,
                    'action'    => 'webhook_cancelled',
                ],
                null,
                "Chapa webhook: payment {$payment->reference} cancelled"
            );
        }

        // 8. Always return 200 to acknowledge receipt
        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /payments/webhook/verify/{reference}
     *
     * Manual verification endpoint — employer or system can trigger verification.
     */
    public function verifyPayment(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $payment = Payment::where('reference', $reference)->first();

        if (!$payment) {
            return $this->sendError('Payment not found.', [], 404);
        }

        // Only the payer or admin can verify
        if ($payment->payer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this payment.');
        }

        // Only pending/processing payments can be verified
        if (!in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            return $this->sendResponse($payment, 'Payment is already in final state: ' . $payment->status);
        }

        try {
            $verified = PaymentService::verifyPayment($payment);
            return $this->sendResponse($verified, 'Payment verification completed.');
        } catch (\Exception $e) {
            return $this->sendError('Verification failed: ' . $e->getMessage(), [], 422);
        }
    }
}
