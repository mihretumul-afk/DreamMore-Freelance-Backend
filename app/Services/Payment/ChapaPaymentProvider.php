<?php

namespace App\Services\Payment;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChapaPaymentProvider — real payment gateway adapter for Chapa.co.
 *
 * Chapa is an Ethiopian payment aggregator supporting:
 *   - Credit/debit cards (Visa, Mastercard)
 *   - Mobile money (Telebirr, M-PESA, CBEBirr)
 *   - Bank transfers
 *
 * Integration pattern:
 *   1. Initialize a transaction via POST /v1/transaction/initialize
 *   2. Redirect user to checkout_url (for web) or show inline form
 *   3. Receive webhook callback on success/failure
 *   4. Verify transaction via GET /v1/transaction/verify/{tx_ref}
 *
 * Credentials are read from config('payment.chapa.*') which in turn
 * reads from .env — never hardcoded.
 */
class ChapaPaymentProvider implements PaymentProviderInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $webhookSecret;
    private string $baseUrl;
    private string $callbackUrl;
    private string $returnUrl;

    public function __construct()
    {
        $this->secretKey     = config('payment.chapa.secret_key', '');
        $this->publicKey     = config('payment.chapa.public_key', '');
        $this->webhookSecret = config('payment.chapa.webhook_secret', '');
        $this->baseUrl       = config('payment.chapa.base_url', 'https://api.chapa.co/v1');
        $this->callbackUrl   = config('payment.chapa.callback_url', '');
        $this->returnUrl     = config('payment.chapa.return_url', '');
    }

    public function getSlug(): string
    {
        return 'chapa';
    }

    public function getName(): string
    {
        return 'Chapa';
    }

    public function isAvailable(): bool
    {
        return !empty($this->secretKey) && !empty($this->publicKey);
    }

    /**
     * Initiate a payment via Chapa's Initialize Transaction API.
     *
     * @see https://developer.chapa.co/docs/initialize-a-transaction
     */
    public function charge(Payment $payment, array $options = []): ProviderResult
    {
        if (!$this->isAvailable()) {
            return ProviderResult::fail('Chapa payment provider is not configured. Check PAYMENT_SECRET_KEY and PAYMENT_PUBLIC_KEY.');
        }

        $txRef = $payment->reference;

        $payload = [
            'amount'        => number_format((float) $payment->amount, 2, '.', ''),
            'currency'      => $payment->currency ?? 'ETB',
            'email'         => $payment->payer?->email ?? '',
            'first_name'    => strtok($payment->payer?->name ?? '', ' '),
            'last_name'     => (strtok(' ') ?? ''),
            'tx_ref'        => $txRef,
            'callback_url'  => $this->callbackUrl,
            'return_url'    => $this->returnUrl,
            'meta'          => [
                'payment_id'     => $payment->id,
                'milestone_id'   => $payment->milestone_id,
                'contract_id'    => $payment->contract_id,
                'platform'       => 'dreammore',
            ],
            'customizations' => [
                'title'       => 'Dream More Marketplace',
                'description' => $payment->description ?? "Payment {$txRef}",
            ],
        ];

        // Allow pre-fill of phone number for mobile money
        if (!empty($options['phone_number'])) {
            $payload['phone_number'] = $options['phone_number'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/transaction/initialize", $payload);

            $body = $response->json();

            if ($response->successful() && isset($body['data']['checkout_url'])) {
                Log::info('Chapa: Transaction initialized', [
                    'tx_ref'       => $txRef,
                    'checkout_url' => $body['data']['checkout_url'],
                ]);

                return ProviderResult::ok(
                    message: 'Payment initialized. Redirect to checkout.',
                    reference: $txRef,
                    raw: $body,
                );
            }

            $errorMsg = $body['message'] ?? 'Unknown error from Chapa.';
            Log::warning('Chapa: Transaction init failed', ['tx_ref' => $txRef, 'error' => $errorMsg, 'response' => $body]);

            return ProviderResult::fail($errorMsg, 'INIT_FAILED', $body);

        } catch (\Exception $e) {
            Log::error('Chapa: Request exception', ['tx_ref' => $txRef, 'error' => $e->getMessage()]);
            return ProviderResult::fail('Payment gateway request failed: ' . $e->getMessage(), 'REQUEST_FAILED');
        }
    }

    /**
     * Verify a transaction with Chapa's Verify Transaction API.
     *
     * @see https://developer.chapa.co/docs/verify-a-transaction
     */
    public function verify(Payment $payment): ProviderResult
    {
        if (!$this->isAvailable()) {
            return ProviderResult::fail('Chapa payment provider is not configured.');
        }

        $txRef = $payment->provider_reference ?? $payment->reference;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Accept'        => 'application/json',
            ])->timeout(15)->get("{$this->baseUrl}/transaction/verify/{$txRef}");

            $body = $response->json();

            if ($response->successful() && isset($body['data'])) {
                $data = $body['data'];

                return ProviderResult::ok(
                    message: 'Transaction verified.',
                    reference: $data['tx_ref'] ?? $txRef,
                    raw: $body,
                );
            }

            return ProviderResult::fail(
                $body['message'] ?? 'Verification failed.',
                'VERIFY_FAILED',
                $body,
            );

        } catch (\Exception $e) {
            Log::error('Chapa: Verify exception', ['tx_ref' => $txRef, 'error' => $e->getMessage()]);
            return ProviderResult::fail('Verification request failed: ' . $e->getMessage(), 'REQUEST_FAILED');
        }
    }

    /**
     * Issue a refund via Chapa's Refund API.
     *
     * @see https://developer.chapa.co/docs/refund-a-transaction
     */
    public function refund(Payment $payment, ?float $amount = null, string $reason = ''): ProviderResult
    {
        if (!$this->isAvailable()) {
            return ProviderResult::fail('Chapa payment provider is not configured.');
        }

        $txRef = $payment->provider_reference ?? $payment->reference;

        $payload = [
            'amount' => number_format($amount ?? (float) $payment->amount, 2, '.', ''),
        ];

        if (!empty($reason)) {
            $payload['reason'] = substr($reason, 0, 255);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/transaction/refund/{$txRef}", $payload);

            $body = $response->json();

            if ($response->successful()) {
                Log::info('Chapa: Refund initiated', ['tx_ref' => $txRef, 'amount' => $payload['amount']]);

                return ProviderResult::ok(
                    message: 'Refund initiated successfully.',
                    reference: $txRef,
                    raw: $body,
                );
            }

            $errorMsg = $body['message'] ?? 'Refund failed.';
            Log::warning('Chapa: Refund failed', ['tx_ref' => $txRef, 'error' => $errorMsg]);

            return ProviderResult::fail($errorMsg, 'REFUND_FAILED', $body);

        } catch (\Exception $e) {
            Log::error('Chapa: Refund exception', ['tx_ref' => $txRef, 'error' => $e->getMessage()]);
            return ProviderResult::fail('Refund request failed: ' . $e->getMessage(), 'REQUEST_FAILED');
        }
    }

    // ── Webhook signature verification ────────────────────────────────

    /**
     * Verify the HMAC signature of an incoming Chapa webhook.
     *
     * Chapa signs webhooks using the PAYMENT_WEBHOOK_SECRET with SHA-512.
     *
     * @param  array  $payload   The raw request body decoded as JSON
     * @param  string $signature The value from the X-CHAPA-Signature header
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            Log::warning('Chapa: Webhook secret not configured — skipping signature verification.');
            return true; // In sandbox without secret, skip verification
        }

        // Chapa sends the transaction reference as the signature
        $expectedSignature = hash_hmac('sha512', json_encode($payload), $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Map Chapa's webhook event status to our internal payment status.
     */
    public function mapWebhookStatus(string $chapaStatus): string
    {
        return match (strtolower($chapaStatus)) {
            'success'   => Payment::STATUS_COMPLETED,
            'failed'    => Payment::STATUS_FAILED,
            'cancelled' => Payment::STATUS_CANCELLED,
            'pending'   => Payment::STATUS_PROCESSING,
            default     => Payment::STATUS_PROCESSING,
        };
    }
}
