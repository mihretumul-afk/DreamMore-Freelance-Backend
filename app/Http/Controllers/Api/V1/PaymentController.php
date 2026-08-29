<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Milestone;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends BaseApiController
{
    /**
     * Fund a milestone (employer pays).
     */
    public function fundMilestone(Request $request, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        $contract = $milestone->contract;
        if (!$contract || $contract->employer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this milestone.');
        }

        $validated = $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        try {
            $paymentService = app(PaymentService::class);
            $payment = $paymentService->fundMilestone($milestone, $user->id, $validated['payment_method_id']);

            return $this->sendResponse([
                'payment' => [
                    'id'          => $payment->id,
                    'reference'   => $payment->reference,
                    'amount'      => (float) $payment->amount,
                    'fee'         => (float) $payment->fee,
                    'net_amount'  => (float) $payment->net_amount,
                    'status'      => $payment->status,
                    'processed_at' => $payment->processed_at?->toIso8601String(),
                ],
                'milestone' => [
                    'id'     => $milestone->id,
                    'status' => $milestone->fresh()->status,
                ],
            ], 'Milestone funded successfully.', 200);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Get payment details for a milestone.
     */
    public function getMilestonePayment(Milestone $milestone): JsonResponse
    {
        $payment = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->first();

        if (!$payment) {
            return $this->sendResponse(null, 'No payment found for this milestone.');
        }

        return $this->sendResponse([
            'id'         => $payment->id,
            'reference'  => $payment->reference,
            'amount'     => (float) $payment->amount,
            'fee'        => (float) $payment->fee,
            'net_amount' => (float) $payment->net_amount,
            'status'     => $payment->status,
            'processed_at' => $payment->processed_at?->toIso8601String(),
        ], 'Payment retrieved.');
    }

    /**
     * List user's payments.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Payment::where(function ($q) use ($user) {
            $q->where('payer_id', $user->id)->orWhere('payee_id', $user->id);
        })->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $payments = $query->paginate(15);

        return $this->sendResponse(
            $payments->items(),
            'Payments retrieved.',
            200,
            [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'total'        => $payments->total(),
            ]
        );
    }
}
