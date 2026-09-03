<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
            'bank_code'             => 'nullable|integer',
            'account_number'        => 'nullable|string|max:50',
            // Mobile money fields
            'mobile_provider' => 'nullable|string|max:50',
            'masked_phone'    => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $isFirst = $user->paymentMethods()->count() === 0;

        // Build display label based on type
        $displayLabel = $this->buildDisplayLabel($validated);

        $methodData = [
            'type'             => $validated['type'],
            'provider'         => $validated['provider'],
            'label'            => $displayLabel,
            'is_default'       => $isFirst,
            'is_verified'      => false,
        ];

        if (Schema::hasColumn('payment_methods', 'nickname')) {
            $methodData['nickname'] = $validated['nickname'] ?? $displayLabel;
        }

        if (Schema::hasColumn('payment_methods', 'display_label')) {
            $methodData['display_label'] = $displayLabel;
        }

        if (Schema::hasColumn('payment_methods', 'card_brand')) {
            $methodData['card_brand'] = $validated['card_brand'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'card_last_four')) {
            $methodData['card_last_four'] = $validated['card_last_four'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'card_exp_month')) {
            $methodData['card_exp_month'] = $validated['card_exp_month'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'card_exp_year')) {
            $methodData['card_exp_year'] = $validated['card_exp_year'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'cardholder_name')) {
            $methodData['cardholder_name'] = $validated['cardholder_name'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'bank_name')) {
            $methodData['bank_name'] = $validated['bank_name'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'account_name')) {
            $methodData['account_name'] = $validated['account_name'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'masked_account_number')) {
            $methodData['masked_account_number'] = $validated['masked_account_number'] ?? null;
        }

        // Auto-map bank_code from provided code, bank_name, or mobile_provider
        if (Schema::hasColumn('payment_methods', 'bank_code')) {
            $bankCode = $validated['bank_code'] ?? null;
            if (!$bankCode) {
                $bankCode = $this->resolveBankCode(
                    $validated['bank_name'] ?? null,
                    $validated['mobile_provider'] ?? null,
                    $validated['provider'] ?? null
                );
            }
            if ($bankCode) {
                $methodData['bank_code'] = $bankCode;
            }
        }

        // Store full account number encrypted (only used internally for payouts)
        if (Schema::hasColumn('payment_methods', 'account_number_encrypted') && !empty($validated['account_number'])) {
            $methodData['account_number_encrypted'] = $validated['account_number'];
            // Also create masked version if not provided
            if (empty($methodData['masked_account_number'])) {
                $acct = $validated['account_number'];
                $methodData['masked_account_number'] = str_repeat('*', max(0, strlen($acct) - 4)) . substr($acct, -4);
            }
        }

        if (Schema::hasColumn('payment_methods', 'mobile_provider')) {
            $methodData['mobile_provider'] = $validated['mobile_provider'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'masked_phone')) {
            $methodData['masked_phone'] = $validated['masked_phone'] ?? null;
        }

        if (Schema::hasColumn('payment_methods', 'provider_token')) {
            $methodData['provider_token'] = 'tok_' . strtoupper(uniqid());
        }

        if (Schema::hasColumn('payment_methods', 'masked_identifier')) {
            $methodData['masked_identifier'] = $validated['card_last_four'] ?? $validated['masked_account_number'] ?? $validated['masked_phone'] ?? null;
        }

        $method = $user->paymentMethods()->create($methodData);

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
            'bank' => ($data['bank_name'] ?? 'Bank') . ' ••••' . substr($data['account_number'] ?? $data['masked_account_number'] ?? '????', -4),
            'mobile_money' => ($data['mobile_provider'] ?? 'Mobile') . ' •••' . substr($data['account_number'] ?? $data['masked_phone'] ?? '????', -4),
            default => $data['nickname'] ?? 'Payment Method',
        };
    }

    /**
     * Resolve a Chapa bank_code from bank name, mobile provider, or provider string.
     */
    private function resolveBankCode(?string $bankName, ?string $mobileProvider, ?string $provider): ?int
    {
        $map = [
            // Banks
            'cbe' => 128, 'commercial bank' => 128, 'commercial bank of ethiopia' => 128, 'cbEBirr' => 128,
            'boa' => 65, 'bank of abyssinia' => 65, 'abyssinia' => 65,
            'awash' => 90, 'awash bank' => 90,
            'dashen' => 115, 'dashen bank' => 115,
            'cib' => 126, 'cooperative bank' => 126,
            'enat' => 1530, 'enat bank' => 1530,
            'hibret' => 1036, 'hibret bank' => 1036,
            'zemen' => 1039, 'zemen bank' => 1039,
            'berhan' => 1042, 'berhan bank' => 1042,
            'abay' => 1044, 'abay bank' => 1044,
            'wegagen' => 1048, 'wegagen bank' => 1048,
            'united' => 1047, 'united bank' => 1047,
            'nib' => 1038, 'nib bank' => 1038,
            'lion' => 1045, 'lion international' => 1045,
            'oromia' => 1156, 'oromia international' => 1156,
            'tsehay' => 1060, 'tsehay bank' => 1060,
            'gohbetom' => 2380, 'gohbetoch' => 2380,
            'sidama' => 1217, 'sidama bank' => 1217,
            'ahadu' => 1219, 'ahadu bank' => 1219,
            'hallie' => 1218, 'hallie bank' => 1218,
            // Mobile money
            'telebirr' => 855, 'tele birr' => 855,
            'cbebirr' => 128, 'cbe birr' => 128,
            'mpesa' => 1079, 'm-pesa' => 1079, 'm pesa' => 1079,
            'amole' => 1185,
            'hellocash' => 1034, 'hello cash' => 1034,
        ];

        // Try each input, normalized
        foreach ([$bankName, $mobileProvider, $provider] as $input) {
            if (!$input) continue;
            $normalized = strtolower(trim($input));
            if (isset($map[$normalized])) {
                return $map[$normalized];
            }
            // Partial match
            foreach ($map as $key => $code) {
                if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                    return $code;
                }
            }
        }

        return null;
    }
}
