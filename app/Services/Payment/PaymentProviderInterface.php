<?php

namespace App\Services\Payment;

use App\Models\Payment;

/**
 * PaymentProviderInterface
 *
 * Every payment gateway adapter must implement this contract.
 * The PaymentService routes calls through this interface, so swapping
 * providers (Manual → Chapa → Stripe) requires only a new adapter class
 * and a config change — no controller or service changes.
 */
interface PaymentProviderInterface
{
    /**
     * Unique slug identifying this provider (e.g. 'manual', 'chapa').
     */
    public function getSlug(): string;

    /**
     * Human-readable provider name shown in the UI.
     */
    public function getName(): string;

    /**
     * Whether this provider is available in the current environment.
     */
    public function isAvailable(): bool;

    /**
     * Initiate a payment.
     *
     * Returns a ProviderResult with:
     *   success     bool
     *   reference   string|null   — gateway's own transaction ID
     *   redirect_url string|null  — for redirect-based flows (Chapa, etc.)
     *   message     string
     *   raw         array         — full gateway response for audit storage
     */
    public function charge(Payment $payment, array $options = []): ProviderResult;

    /**
     * Verify the current status of a payment with the gateway.
     */
    public function verify(Payment $payment): ProviderResult;

    /**
     * Issue a full or partial refund.
     *
     * @param  float|null  $amount  Partial amount; null = full refund
     */
    public function refund(Payment $payment, ?float $amount = null, string $reason = ''): ProviderResult;
}
