<?php

namespace App\Services\Payment;

/**
 * PaymentProviderInterface — provider-agnostic contract for payment processing.
 * All payment adapters must implement this interface.
 */
interface PaymentProviderInterface
{
    /**
     * Initialize a payment charge.
     *
     * @param float  $amount       Amount to charge
     * @param string $currency     Currency code (e.g. ETB)
     * @param string $reference    Unique payment reference
     * @param array  $metadata     Additional metadata (user_id, milestone_id, etc.)
     * @return array{success: bool, provider_reference: string|null, error: string|null}
     */
    public function charge(float $amount, string $currency, string $reference, array $metadata = []): array;

    /**
     * Process a refund.
     *
     * @param string $providerReference Original provider transaction reference
     * @param float  $amount            Refund amount
     * @param string $reason            Refund reason
     * @return array{success: bool, provider_reference: string|null, error: string|null}
     */
    public function refund(string $providerReference, float $amount, string $reason = ''): array;

    /**
     * Verify a payment by provider reference.
     *
     * @param string $providerReference Provider transaction ID
     * @return array{status: string, amount: float|null, error: string|null}
     */
    public function verify(string $providerReference): array;

    /**
     * Verify webhook signature.
     *
     * @param string $payload    Raw request body
     * @param string $signature  Signature from headers
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Process a payout/withdrawal.
     *
     * @param float  $amount    Amount to send
     * @param string $currency  Currency code
     * @param string $reference Unique withdrawal reference
     * @param array  $recipient Recipient details (account, mobile, etc.)
     * @return array{success: bool, provider_reference: string|null, error: string|null}
     */
    public function payout(float $amount, string $currency, string $reference, array $recipient = []): array;

    /**
     * Get provider name.
     */
    public function getName(): string;
}
