<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin\PaymentController — finance admin views and actions on payments.
 *
 * All routes require: auth:sanctum + role:admin + permission:payments.*
 */
class PaymentController extends BaseApiController
{
    // ── Dashboard stats ───────────────────────────────────────────────

    /**
     * GET /admin/payments/stats
     * High-level financial stats for the admin payment dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.view')) {
            return $this->sendForbidden('Insufficient permission.');
        }

        $totalVolume = Payment::where('status', Payment::STATUS_COMPLETED)
            ->whereNotIn('type', [Payment::TYPE_REFUND])
            ->sum('amount');

        $totalFees = Payment::where('status', Payment::STATUS_COMPLETED)
            ->sum('fee');

        $totalRefunded = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_REFUND)
            ->sum('amount');

        $pendingCount = Payment::whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->count();

        $failedCount = Payment::where('status', Payment::STATUS_FAILED)
            ->count();

        $escrowHeld = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->whereDoesntHave('milestone', fn ($q) => $q->where('status', 'paid'))
            ->sum('amount');

        $byType = Payment::selectRaw('type, count(*) as count, sum(amount) as total')
            ->where('status', Payment::STATUS_COMPLETED)
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return $this->sendResponse([
            'total_volume'    => (float) $totalVolume,
            'total_fees'      => (float) $totalFees,
            'total_refunded'  => (float) $totalRefunded,
            'escrow_held'     => (float) $escrowHeld,
            'pending_count'   => $pendingCount,
            'failed_count'    => $failedCount,
            'currency'        => 'ETB',
            'by_type'         => $byType,
        ], 'Payment stats retrieved.');
    }

    // ── Payment list ──────────────────────────────────────────────────

    /**
     * GET /admin/payments
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.view')) {
            return $this->sendForbidden('Insufficient permission.');
        }

        $query = Payment::with([
            'payer:id,name,email',
            'payee:id,name,email',
            'contract:id,title',
            'milestone:id,title',
            'paymentMethod:id,type,display_label,masked_identifier',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
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
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhereHas('payer', fn ($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                  ->orWhereHas('payee', fn ($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            });
        }

        $payments = $query->paginate(20);

        return $this->sendResponse(
            $payments->items(),
            'Payments retrieved.',
            200,
            [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ]
        );
    }

    /**
     * GET /admin/payments/{payment}
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.view')) {
            return $this->sendForbidden('Insufficient permission.');
        }

        $payment->load([
            'payer:id,name,email,role',
            'payee:id,name,email,role',
            'contract:id,title,status',
            'milestone:id,title,amount,status',
            'paymentMethod:id,type,display_label,masked_identifier',
            'transactions',
        ]);

        return $this->sendResponse($payment, 'Payment retrieved.');
    }

    // ── Verify a pending payment ──────────────────────────────────────

    /**
     * PUT /admin/payments/{payment}/verify
     */
    public function verify(Request $request, Payment $payment): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.verify')) {
            return $this->sendForbidden('You do not have permission to verify payments.');
        }

        try {
            $updated = PaymentService::adminVerify($payment, $request->user()->id);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($updated, 'Payment verified successfully.');
    }

    // ── Refund a payment ──────────────────────────────────────────────

    /**
     * POST /admin/payments/{payment}/refund
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.refund')) {
            return $this->sendForbidden('You do not have permission to issue refunds.');
        }

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $refundPayment = PaymentService::adminRefund(
                $payment,
                $request->user()->id,
                $validated['amount'] ?? null,
                $validated['reason'] ?? ''
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($refundPayment, 'Refund issued successfully.');
    }

    // ── Refund request management ─────────────────────────────────────

    /**
     * GET /admin/refund-requests
     * Lists all pending refund requests for admin review.
     */
    public function refundRequests(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.refund')) {
            return $this->sendForbidden('You do not have permission to view refund requests.');
        }

        $query = Payment::where('refund_status', '!=', Payment::REFUND_STATUS_NONE)
            ->with([
                'payer:id,name,email',
                'contract:id,title',
                'milestone:id,title,amount',
                'refundRequester:id,name,email',
                'refundApprover:id,name,email',
            ])
            ->orderByDesc('refund_requested_at');

        if ($request->filled('refund_status')) {
            $query->where('refund_status', $request->input('refund_status'));
        }

        $payments = $query->paginate(20);

        return $this->sendResponse(
            $payments->items(),
            'Refund requests retrieved.',
            200,
            [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ]
        );
    }

    /**
     * PUT /admin/payments/{payment}/refund-approve
     * Approve a pending refund request and process the refund.
     */
    public function approveRefund(Request $request, Payment $payment): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.refund')) {
            return $this->sendForbidden('You do not have permission to approve refunds.');
        }

        if ($payment->refund_status !== Payment::REFUND_STATUS_REQUESTED) {
            return $this->sendError('This payment does not have a pending refund request.', [], 422);
        }

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $refund = PaymentService::approveRefund(
                $payment,
                $request->user()->id,
                $validated['amount'] ?? null,
                $validated['note'] ?? ''
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($refund, 'Refund approved and processed successfully.');
    }

    /**
     * PUT /admin/payments/{payment}/refund-reject
     * Reject a pending refund request.
     */
    public function rejectRefund(Request $request, Payment $payment): JsonResponse
    {
        if (!$request->user()->hasPermission('payments.refund')) {
            return $this->sendForbidden('You do not have permission to reject refunds.');
        }

        if ($payment->refund_status !== Payment::REFUND_STATUS_REQUESTED) {
            return $this->sendError('This payment does not have a pending refund request.', [], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = PaymentService::rejectRefund(
                $payment,
                $request->user()->id,
                $validated['reason'] ?? ''
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($updated, 'Refund request rejected.');
    }
}
