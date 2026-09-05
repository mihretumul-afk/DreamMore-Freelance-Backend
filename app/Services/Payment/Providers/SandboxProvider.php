<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\PaymentProviderInterface;

/**
 * SandboxProvider — simulates payment processing for development/testing.
 * NEVER use in production. Simulates all operations locally.
 */
class SandboxProvider implements PaymentProviderInterface
{
    public function charge(float $amount, string $currency, string $reference, array $metadata = []): array
    {
        // Simulate: always succeeds in sandbox
        $providerRef = 'SANDBOX-' . strtoupper(uniqid());

        \Illuminate\Support\Facades\Log::info('[Sandbox Payment] Charge simulated', [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'provider_ref' => $providerRef,
        ]);

        return [
            'success' => true,
            'provider_reference' => $providerRef,
            'error' => null,
        ];
    }

    public function refund(string $providerReference, float $amount, string $reason = ''): array
    {
        $providerRef = 'SANDBOX-REF-' . strtoupper(uniqid());

        \Illuminate\Support\Facades\Log::info('[Sandbox Payment] Refund simulated', [
            'original_ref' => $providerReference,
            'amount' => $amount,
            'provider_ref' => $providerRef,
        ]);

        return [
            'success' => true,
            'provider_reference' => $providerRef,
            'error' => null,
        ];
    }

    public function verify(string $providerReference): array
    {
        return [
            'status' => 'completed',
            'amount' => null,
            'error' => null,
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // Sandbox: accept all signatures
        return true;
    }

    public function payout(float $amount, string $currency, string $reference, array $recipient = []): array
    {
        $providerRef = 'SANDBOX-PAYOUT-' . strtoupper(uniqid());

        \Illuminate\Support\Facades\Log::info('[Sandbox Payment] Payout simulated', [
            'reference' => $reference,
            'amount' => $amount,
            'provider_ref' => $providerRef,
        ]);

        return [
            'success' => true,
            'provider_reference' => $providerRef,
            'error' => null,
        ];
    }

    public function listBanks(): array
    {
        // No real bank list in sandbox — the frontend falls back to free-text
        // bank details since payouts are simulated.
        return [];
    }

    public function getName(): string
    {
        return 'sandbox';
    }
}
