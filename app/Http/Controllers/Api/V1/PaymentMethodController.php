<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends BaseApiController
{
    /**
     * List user's payment methods.
     */
    public function index(Request $request): JsonResponse
    {
        $methods = $request->user()
            ->paymentMethods()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($methods, 'Payment methods retrieved.');
    }

    /**
     * Add a payment method.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'          => 'required|in:card,bank,mobile_money',
            'provider'      => 'required|string|max:50',
            'nickname'      => 'nullable|string|max:255',
            // Card fields
            'card_brand'        => 'nullable|string|max:50',
            'card_last_four'    => 'nullable|string|max:4',
            'card_exp_month'    => 'nullable|string|max:2',
            'card_exp_year'     => 'nullable|string|max:4',
            'cardholder_name'   => 'nullable|string|max:255',
            // Bank fields
            'bank_name'             => 'nullable|string|max:100',
            'account_name'          => 'nullable|string|max:255',
            'masked_account_number' => 'nullable|string|max:20',
            // Mobile money fields
            'mobile_provider' => 'nullable|string|max:50',
            'masked_phone'    => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $isFirst = $user->paymentMethods()->count() === 0;

        // Build display label based on type
        $displayLabel = $this->buildDisplayLabel($validated);

        $method = $user->paymentMethods()->create([
            'type'             => $validated['type'],
            'nickname'         => $validated['nickname'] ?? $displayLabel,
            'provider'         => $validated['provider'],
            'label'            => $displayLabel,
            'display_label'    => $displayLabel,
            'card_brand'       => $validated['card_brand'] ?? null,
            'card_last_four'   => $validated['card_last_four'] ?? null,
            'card_exp_month'   => $validated['card_exp_month'] ?? null,
            'card_exp_year'    => $validated['card_exp_year'] ?? null,
            'cardholder_name'  => $validated['cardholder_name'] ?? null,
            'bank_name'        => $validated['bank_name'] ?? null,
            'account_name'     => $validated['account_name'] ?? null,
            'masked_account_number' => $validated['masked_account_number'] ?? null,
            'mobile_provider'  => $validated['mobile_provider'] ?? null,
            'masked_phone'     => $validated['masked_phone'] ?? null,
            'is_default'       => $isFirst,
            'is_verified'      => false,
            'provider_token'   => 'tok_' . strtoupper(uniqid()),
            'masked_identifier' => $validated['card_last_four'] ?? $validated['masked_account_number'] ?? $validated['masked_phone'] ?? null,
        ]);

        AuditService::paymentMethodAdded($method->id, $user->id, [
            'type'     => $validated['type'],
            'provider' => $validated['provider'],
        ]);

        return $this->sendResponse($method, 'Payment method added.', 201);
    }

    /**
     * Set a payment method as default.
     */
    public function setDefault(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not have access to this payment method.');
        }

        $paymentMethod->setAsDefault();

        return $this->sendResponse($paymentMethod->fresh(), 'Default payment method updated.');
    }

    /**
     * Remove a payment method.
     */
    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not have access to this payment method.');
        }

        AuditService::paymentMethodRemoved($paymentMethod->id, $request->user()->id, [
            'display_label' => $paymentMethod->display_label,
        ]);

        $paymentMethod->delete();

        return $this->sendResponse(null, 'Payment method removed.');
    }

    /**
     * Build a display label from the validated data.
     */
    private function buildDisplayLabel(array $data): string
    {
        return match ($data['type']) {
            'card' => ($data['card_brand'] ? ucfirst($data['card_brand']) . ' ' : '') . '••••' . ($data['card_last_four'] ?? '????'),
            'bank' => ($data['bank_name'] ?? 'Bank') . ' ••••' . ($data['masked_account_number'] ?? '????'),
            'mobile_money' => ($data['mobile_provider'] ?? 'Mobile') . ' •••' . (substr($data['masked_phone'] ?? '', -4) ?: '????'),
            default => $data['nickname'] ?? 'Payment Method',
        };
    }
}
