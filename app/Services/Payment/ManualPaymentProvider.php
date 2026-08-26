<?php

namespace App\Services\Payment;

use App\Models\Payment;

/**
 * ManualPaymentProvider
 *
 * Foundation-stage provider that simulates successful payment flows
 * without calling any external gateway. All operations succeed instantly.
 *
 * This is INTENTIONALLY not a real payment processor. It exists so the
 * entire payment architecture (models, service, controllers, UI) can be
 * built and tested before a real gateway is integrated in Stage 2.
 *
 * To add a real provider:
 *   1. Create e.g. ChapaPaymentProvider implements PaymentProviderInterface
 *   2. Register it in PaymentService::resolveProvider()
 *   3. Set PAYMENT_PROVIDER=chapa in .env
 *   4. Install the gateway SDK via composer
 */
class ManualPaymentProvider implements PaymentProviderInterface
{
    public function getSlug(): string
    {
        return 'manual';
    }

    public function getName(): string
    {
        return 'Manual (Foundation)';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function charge(Payment $payment, array $options = []): ProviderResult
    {
        // In foundation mode we immediately mark the payment as successful.
        // A real provider would call an API here and possibly return a redirect_url.
        return ProviderResult::ok(
            message: 'Payment recorded successfully.',
            reference: 'MANUAL-' . strtoupper(substr(md5($payment->reference . microtime()), 0, 12)),
            raw: [
                'provider'   => $this->getSlug(),
                'simulated'  => true,
                'payment_id' => $payment->id,
                'timestamp'  => now()->toIso8601String(),
            ]
        );
    }

    public function verify(Payment $payment): ProviderResult
    {
        // Manual payments are always in the state they were last set to.
        return ProviderResult::ok(
            message: 'Verification skipped — manual provider.',
            reference: $payment->provider_reference,
            raw: ['provider' => $this->getSlug(), 'simulated' => true]
        );
    }

    public function refund(Payment $payment, ?float $amount = null, string $reason = ''): ProviderResult
    {
        $refundAmount = $amount ?? (float) $payment->amount;

        return ProviderResult::ok(
            message: "Refund of {$payment->currency} {$refundAmount} recorded.",
            reference: 'REF-' . strtoupper(substr(md5($payment->reference . 'refund'), 0, 10)),
            raw: [
                'provider'      => $this->getSlug(),
                'simulated'     => true,
                'refund_amount' => $refundAmount,
                'reason'        => $reason,
                'timestamp'     => now()->toIso8601String(),
            ]
        );
    }
}
