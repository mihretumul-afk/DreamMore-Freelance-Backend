<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Contract;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PaymentController — user-facing payment endpoints.
 *
 * All endpoints require auth:sanctum.
 * Users only ever see their own data — every query is scoped by user_id.
 */
class PaymentController extends BaseApiController
{
    // ════════════════════════════════════════════════════════════════════
    // BALANCE
    // ════════════════════════════════════════════════════════════════════

    /**
     * GET /payments/balance
     * Returns available, pending, total_earned, total_spent for the
     * authenticated user. Works for both freelancers and employers.
     */
    public function balance(Request $request): JsonResponse
    {
        $summary = PaymentService::getBalanceSummary($request->user()->id);

        return $this->sendResponse($summary, 'Balance retrieved successfully.');
    }

    // ════════════════════════════════════════════════════════════════════
    // PAYMENT METHODS
    // ════════════════════════════════════════════════════════════════════

    /**
     * GET /payments/methods
     */
    public function listMethods(Request $request): JsonResponse
    {
        $methods = PaymentMethod::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($methods, 'Payment methods retrieved.');
    }

    /**
     * POST /payments/methods
     *
     * Add a payment method. Raw card numbers and CVV are NEVER accepted.
     * Only masked/display identifiers are stored.
     */
    public function addMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'                  => ['required', 'in:card,bank_account,mobile_money'],
            'nickname'              => ['nullable', 'string', 'max:100'],
            'is_default'            => ['boolean'],

            // Card fields (no full PAN, no CVV)
            'card_brand'            => ['nullable', 'string', 'max:20'],
            'card_last_four'        => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'card_exp_month'        => ['nullable', 'string', 'size:2', 'regex:/^(0[1-9]|1[0-2])$/'],
            'card_exp_year'         => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'cardholder_name'       => ['nullable', 'string', 'max:100'],

            // Bank account
            'bank_name'             => ['nullable', 'string', 'max:100'],
            'account_name'          => ['nullable', 'string', 'max:100'],
            'masked_account_number' => ['nullable', 'string', 'max:50'],

            // Mobile money
            'mobile_provider'       => ['nullable', 'string', 'max:50'],
            'masked_phone'          => ['nullable', 'string', 'max:20'],
        ]);

        // Build display_label from type-specific fields.
        $displayLabel = match ($validated['type']) {
            'card'         => trim(($validated['card_brand'] ?? 'Card') . ' ending in ' . ($validated['card_last_four'] ?? '****')),
            'bank_account' => trim(($validated['bank_name'] ?? 'Bank') . ' ' . ($validated['masked_account_number'] ?? '')),
            'mobile_money' => trim(($validated['mobile_provider'] ?? 'Mobile') . ' ' . ($validated['masked_phone'] ?? '')),
            default        => $validated['nickname'] ?? 'Payment Method',
        };
        $validated['display_label'] = $displayLabel;

        $method = PaymentService::addPaymentMethod($request->user()->id, $validated);

        AuditService::paymentMethodAdded($method->id, $request->user()->id, [
            'type'          => $method->type,
            'display_label' => $method->display_label,
        ]);

        return $this->sendResponse($method, 'Payment method added.', 201);
    }

    /**
     * GET /payments/methods/{method}
     */
    public function showMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not own this payment method.');
        }

        return $this->sendResponse($paymentMethod, 'Payment method retrieved.');
    }

    /**
     * PUT /payments/methods/{method}/default
     */
    public function setDefaultMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not own this payment method.');
        }

        $method = PaymentService::setDefaultPaymentMethod(
            $request->user()->id,
            $paymentMethod->id
        );

        return $this->sendResponse($method, 'Default payment method updated.');
    }

    /**
     * DELETE /payments/methods/{method}
     */
    public function removeMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not own this payment method.');
        }

        try {
            AuditService::paymentMethodRemoved($paymentMethod->id, $request->user()->id, [
                'type'          => $paymentMethod->type,
                'display_label' => $paymentMethod->getDisplayLabel(),
            ]);

            PaymentService::removePaymentMethod($request->user()->id, $paymentMethod->id);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse(null, 'Payment method removed.');
    }

    // ════════════════════════════════════════════════════════════════════
    // TRANSACTIONS
    // ════════════════════════════════════════════════════════════════════

    /**
     * GET /payments/transactions
     * Paginated, filterable transaction history for the authenticated user.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $request->user()->id)
            ->with(['payment', 'contract:id,title', 'milestone:id,title'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $transactions = $query->paginate(15);

        return $this->sendResponse(
            $transactions->items(),
            'Transactions retrieved.',
            200,
            [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'per_page'     => $transactions->perPage(),
                'total'        => $transactions->total(),
            ]
        );
    }

    /**
     * GET /payments/transactions/{transaction}
     */
    public function showTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not have access to this transaction.');
        }

        $transaction->load([
            'payment.paymentMethod',
            'contract:id,title,employer_id,freelancer_id',
            'milestone:id,title,amount',
        ]);

        return $this->sendResponse($transaction, 'Transaction retrieved.');
    }

    // ════════════════════════════════════════════════════════════════════
    // ESCROW / MILESTONE PAYMENTS
    // ════════════════════════════════════════════════════════════════════

    /**
     * POST /contracts/{contract}/milestones/{milestone}/fund
     *
     * Employer funds a milestone into escrow.
     */
    public function fundMilestone(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can fund milestones.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone does not belong to this contract.', [], 422);
        }

        $validated = $request->validate([
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ]);

        $paymentMethod = null;
        if (!empty($validated['payment_method_id'])) {
            $paymentMethod = PaymentMethod::where('id', $validated['payment_method_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$paymentMethod) {
                return $this->sendError('Payment method not found.', [], 404);
            }
        }

        try {
            $payment = PaymentService::fundMilestoneEscrow(
                $contract,
                $milestone,
                $paymentMethod,
                $user->id
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse(
            $payment->load('milestone:id,title,status,escrow_funded_at'),
            'Milestone escrow funded successfully.'
        );
    }

    /**
     * POST /contracts/{contract}/milestones/{milestone}/release
     *
     * Employer releases an approved milestone's escrow to the freelancer.
     */
    public function releaseMilestone(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can release milestone payments.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone does not belong to this contract.', [], 422);
        }

        try {
            $payment = PaymentService::releaseMilestonePayment(
                $contract,
                $milestone,
                $user->id
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse(
            $payment->load('milestone:id,title,status,paid_at'),
            'Milestone payment released to freelancer.'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // REFUND REQUEST (user-facing)
    // ════════════════════════════════════════════════════════════════════

    /**
     * POST /contracts/{contract}/milestones/{milestone}/refund-request
     *
     * Employer requests a refund on a funded-but-not-released milestone.
     * The actual refund is NOT automatic — an admin must approve it.
     */
    public function requestMilestoneRefund(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can request a refund.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone does not belong to this contract.', [], 422);
        }

        if (!$milestone->isEscrowFunded()) {
            return $this->sendError('This milestone has not been funded — no refund is possible.', [], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // Load the escrow payment for this milestone.
        $payment = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('status', Payment::STATUS_COMPLETED)
            ->latest()
            ->first();

        if (!$payment) {
            return $this->sendError('No funded escrow payment found for this milestone.', [], 404);
        }

        try {
            $updated = PaymentService::requestRefund($payment, $user->id, $validated['reason']);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($updated, 'Refund request submitted. An administrator will review it shortly.');
    }

    /**
     * GET /payments/refund-requests
     *
     * Employer views their own refund requests.
     */
    public function myRefundRequests(Request $request): JsonResponse
    {
        $requests = Payment::where('payer_id', $request->user()->id)
            ->where('refund_status', '!=', Payment::REFUND_STATUS_NONE)
            ->with(['milestone:id,title', 'contract:id,title'])
            ->orderByDesc('refund_requested_at')
            ->paginate(10);

        return $this->sendResponse(
            $requests->items(),
            'Refund requests retrieved.',
            200,
            [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ]
        );
    }
}
