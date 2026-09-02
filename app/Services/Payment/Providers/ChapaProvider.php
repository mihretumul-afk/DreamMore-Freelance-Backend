<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChapaProvider — payment adapter for the Chapa payment gateway.
 *
 * Chapa is an Ethiopian payment gateway supporting Telebirr, CBE Birr,
 * and international cards. It uses a redirect-based checkout flow.
 *
 * Flow:
 * 1. Initialize transaction via Chapa API → get checkout_url
 * 2. User is redirected to checkout_url to complete payment
 * 3. Chapa sends webhook to confirm payment
 * 4. Alternatively, verify payment via API polling
 *
 * @see https://developer.chapa.co
 */
class ChapaProvider implements PaymentProviderInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('payment.chapa.secret_key', '');
        $this->publicKey = config('payment.chapa.public_key', '');
        $this->baseUrl = config('payment.chapa.base_url', 'https://api.chapa.co');
    }

    /**
     * Create an HTTP client with proper SSL settings.
     * SSL verification is ONLY disabled in local/testing environments
     * where Windows dev machines may lack CA certificates.
     * NEVER disabled in production — uses APP_ENV, not APP_DEBUG.
     */
    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type'  => 'application/json',
        ])->timeout(30);

        // Only disable SSL in local/testing — NOT production.
        // Uses APP_ENV (not APP_DEBUG) so this is safe even if
        // APP_DEBUG is accidentally left on in production.
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    /**
     * Initialize a payment via Chapa.
     *
     * Returns checkout_url in the response so the frontend can redirect
     * the user to Chapa's hosted checkout page.
     */
    public function charge(float $amount, string $currency, string $reference, array $metadata = []): array
    {
        try {
            $callbackUrl = config('payment.chapa.callback_url', url('/api/v1/webhooks/deposit/chapa'));
            $returnUrl = $metadata['return_url'] ?? config('payment.chapa.return_url', url('/wallet'));

            $payload = [
                'key'          => $this->publicKey,
                'amount'       => number_format($amount, 2, '.', ''),
                'currency'     => $currency,
                'tx_ref'       => $reference,
                'callback_url' => $callbackUrl,
                'return_url'   => $returnUrl,
            ];

            // Add optional customer details if available
            if (!empty($metadata['email'])) {
                $payload['email'] = $metadata['email'];
            }
            if (!empty($metadata['phone_number'])) {
                $payload['phone_number'] = $metadata['phone_number'];
            }
            if (!empty($metadata['first_name'])) {
                $payload['first_name'] = $metadata['first_name'];
            }
            if (!empty($metadata['last_name'])) {
                $payload['last_name'] = $metadata['last_name'];
            }

            // Customization (title max 16 chars per Chapa API)
            $title = config('payment.chapa.title', 'DreamMore');
            if (mb_strlen($title) > 16) {
                $title = mb_substr($title, 0, 16);
            }
            $payload['customization'] = [
                'title'       => $title,
                'description' => config('payment.chapa.description', 'Complete your payment'),
            ];

            Log::info('[Chapa] Initializing transaction', [
                'reference' => $reference,
                'amount'    => $amount,
                'currency'  => $currency,
            ]);

            $response = $this->httpClient()->post($this->baseUrl . '/v1/transaction/initialize', $payload);

            Log::info('[Chapa] API response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if ($response->failed()) {
                $error = $this->extractError($response);
                Log::error('[Chapa] Charge failed', [
                    'reference'    => $reference,
                    'status_code'  => $response->status(),
                    'body'         => $response->body(),
                    'error'        => $error,
                ]);

                return [
                    'success'            => false,
                    'provider_reference' => null,
                    'checkout_url'       => null,
                    'error'              => $error,
                ];
            }

            $body = $response->json();
            $data = $body['data'] ?? [];

            Log::info('[Chapa] Transaction initialized', [
                'reference'     => $reference,
                'tx_ref'        => $data['tx_ref'] ?? null,
                'checkout_url'  => $data['checkout_url'] ?? null,
            ]);

            return [
                'success'            => true,
                'provider_reference' => $data['tx_ref'] ?? $reference,
                'checkout_url'       => $data['checkout_url'] ?? null,
                'provider_response'  => $data,
                'error'              => null,
            ];
        } catch (\Exception $e) {
            Log::error('[Chapa] Charge exception', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'            => false,
                'provider_reference' => null,
                'checkout_url'       => null,
                'error'              => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a payment by its transaction reference.
     */
    public function verify(string $providerReference): array
    {
        try {
            $response = $this->httpClient()->get($this->baseUrl . '/v1/transaction/verify/' . $providerReference);

            if ($response->failed()) {
                return [
                    'status' => 'unknown',
                    'amount' => null,
                    'error'  => $this->extractError($response),
                ];
            }

            $body = $response->json();
            $data = $body['data'] ?? [];

            // Map Chapa status to our statuses
            $chapaStatus = strtolower($data['status'] ?? 'unknown');
            $status = match ($chapaStatus) {
                'success'  => 'completed',
                'failed'   => 'failed',
                'cancelled' => 'cancelled',
                'pending'  => 'pending',
                default    => 'unknown',
            };

            $amount = isset($data['amount']) ? (float) $data['amount'] : null;

            Log::info('[Chapa] Payment verified', [
                'tx_ref'  => $providerReference,
                'status'  => $status,
                'amount'  => $amount,
            ]);

            return [
                'status' => $status,
                'amount' => $amount,
                'error'  => null,
                'data'   => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[Chapa] Verify exception', [
                'tx_ref' => $providerReference,
                'error'  => $e->getMessage(),
            ]);

            return [
                'status' => 'unknown',
                'amount' => null,
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a refund via Chapa.
     *
     * NOTE: Chapa's refund API may require manual processing or
     * a separate agreement. This calls the transfer endpoint to
     * return funds if supported.
     */
    public function refund(string $providerReference, float $amount, string $reason = ''): array
    {
        Log::info('[Chapa] Refund requested', [
            'original_ref' => $providerReference,
            'amount'       => $amount,
            'reason'       => $reason,
        ]);

        // Chapa doesn't provide a direct refund API in all plans.
        // For now, log the refund request. In production, this may
        // need to be handled via Chapa dashboard or support.
        Log::warning('[Chapa] Refund via API not yet supported. Manual refund may be required.', [
            'tx_ref' => $providerReference,
            'amount' => $amount,
        ]);

        return [
            'success'            => false,
            'provider_reference' => null,
            'error'              => 'Refund via Chapa API requires manual processing. Please contact support.',
        ];
    }

    /**
     * Verify a Chapa webhook signature.
     *
     * Chapa sends an HMAC-SHA256 signature in the x-chapa-signature header.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($signature)) {
            Log::warning('[Chapa] No webhook signature provided');
            return false;
        }

        $secretKey = $this->secretKey;
        if (empty($secretKey)) {
            Log::error('[Chapa] Secret key not configured for webhook verification');
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process a payout (withdrawal) via Chapa Transfer API.
     *
     * @see https://developer.chapa.co
     */
    public function payout(float $amount, string $currency, string $reference, array $recipient = []): array
    {
        try {
            $accountName = $recipient['account_name'] ?? '';
            $accountNumber = $recipient['account_number'] ?? $recipient['phone'] ?? '';
            $bankCode = $recipient['bank_code'] ?? null;

            if (empty($accountNumber)) {
                return [
                    'success'            => false,
                    'provider_reference' => null,
                    'error'              => 'Recipient account number is required.',
                ];
            }

            $payload = [
                'title'         => 'Withdrawal ' . $reference,
                'currency'      => $currency,
                'amount'        => number_format($amount, 2, '.', ''),
                'account_name'  => $accountName,
                'account_number' => $accountNumber,
                'reference'     => $reference,
            ];

            if ($bankCode) {
                $payload['bank_code'] = $bankCode;
            }

            Log::info('[Chapa] Initiating transfer', [
                'reference' => $reference,
                'amount'    => $amount,
                'currency'  => $currency,
            ]);

            $response = $this->httpClient()->post($this->baseUrl . '/v1/transfers', $payload);

            if ($response->failed()) {
                $error = $this->extractError($response);
                Log::error('[Chapa] Transfer failed', [
                    'reference'   => $reference,
                    'status_code' => $response->status(),
                    'error'       => $error,
                ]);

                return [
                    'success'            => false,
                    'provider_reference' => null,
                    'error'              => $error,
                ];
            }

            $body = $response->json();
            $data = $body['data'] ?? [];

            Log::info('[Chapa] Transfer successful', [
                'reference' => $reference,
                'ref'       => $data['ref'] ?? null,
            ]);

            return [
                'success'            => true,
                'provider_reference' => $data['ref'] ?? $reference,
                'provider_response'  => $data,
                'error'              => null,
            ];
        } catch (\Exception $e) {
            Log::error('[Chapa] Transfer exception', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'            => false,
                'provider_reference' => null,
                'error'              => $e->getMessage(),
            ];
        }
    }

    /**
     * Get provider name.
     */
    public function getName(): string
    {
        return 'chapa';
    }

    /**
     * Extract error message from a failed HTTP response.
     */
    private function extractError($response): string
    {
        $body = $response->json();

        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }
        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }
        if (isset($body['errors'][0]['message']) && is_string($body['errors'][0]['message'])) {
            return $body['errors'][0]['message'];
        }

        return 'Chapa API error (HTTP ' . $response->status() . ')';
    }
}
