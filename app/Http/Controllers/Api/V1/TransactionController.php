<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TransactionController extends BaseApiController
{
    /**
     * List user's transactions (financial ledger).
     *
     * Returns a unified view combining:
     *  1. Explicit ledger transactions from the transactions table
     *  2. Virtual transactions derived from payment records where the user
     *     is payer or payee (so payments that never got a ledger entry still
     *     appear in the billing/earnings history).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        // ── 1. Fetch existing ledger transactions ────────────────────────
        $ledgerTxs = Transaction::where('user_id', $userId)
            ->with([
                'payment.payee' => fn ($q) => $q->select('id', 'name', 'email'),
                'payment.milestone.contract.freelancer' => fn ($q) => $q->select('id', 'name', 'email'),
            ])
            ->orderByDesc('created_at')
            ->get();

        // Collect payment IDs that already have a ledger transaction for this
        // user — including soft-deleted (hidden) entries, so payments whose
        // history entry was removed never resurface as virtual transactions.
        $linkedPaymentIds = Transaction::withTrashed()
            ->where('user_id', $userId)
            ->whereNotNull('payment_id')
            ->pluck('payment_id')
            ->unique()
            ->values();

        // ── 2. Fetch payments that have NO matching ledger transaction ────
        // These are payments created via seeders or direct DB inserts that
        // skipped the normal PaymentService flow.
        $orphanPayments = Payment::where(function ($q) use ($userId) {
                $q->where('payer_id', $userId)
                  ->orWhere('payee_id', $userId);
            })
            ->whereNotIn('id', $linkedPaymentIds)
            ->with([
                'payee' => fn ($q) => $q->select('id', 'name', 'email'),
                'milestone.contract.freelancer' => fn ($q) => $q->select('id', 'name', 'email'),
            ])
            ->orderByDesc('created_at')
            ->get();

        // ── 3. Convert orphan payments into virtual transaction shapes ───
        $virtualTxs = $orphanPayments->map(function (Payment $payment) use ($userId) {
            // Determine direction: if user is payer → debit, if payee → credit
            $isPayer = $payment->payer_id == $userId;
            $direction = $isPayer ? Transaction::DIR_DEBIT : Transaction::DIR_CREDIT;

            // Determine type from payment type
            $type = match ($payment->type) {
                Payment::TYPE_ESCROW_FUNDED     => Transaction::TYPE_FUNDS_HELD,
                Payment::TYPE_MILESTONE_RELEASED => Transaction::TYPE_FUNDS_RELEASED,
                Payment::TYPE_REFUND             => Transaction::TYPE_REFUND,
                default                          => 'payment',
            };

            return [
                'id'             => 'virtual_' . $payment->id,
                'reference'      => $payment->reference,
                'user_id'        => $userId,
                'payment_id'     => $payment->id,
                'direction'      => $direction,
                'type'           => $type,
                'amount'         => (float) $payment->amount,
                'balance_before' => 0,
                'balance_after'  => 0,
                'currency'       => $payment->currency ?? 'ETB',
                'status'         => Transaction::STATUS_COMPLETED,
                'description'    => $payment->description ?? match ($payment->type) {
                    Payment::TYPE_ESCROW_FUNDED     => "Funds held for milestone",
                    Payment::TYPE_MILESTONE_RELEASED => "Funds released for milestone",
                    Payment::TYPE_REFUND             => "Refund received",
                    default                          => "Payment",
                },
                'created_at'     => $payment->created_at?->toISOString(),
                'freelancer'     => $this->extractFreelancer($payment),
                'payment'        => [
                    'id'        => $payment->id,
                    'reference' => $payment->reference,
                    'type'      => $payment->type,
                    'status'    => $payment->status,
                    'amount'    => (float) $payment->amount,
                    'payee'     => $payment->payee ? [
                        'id'    => $payment->payee->id,
                        'name'  => $payment->payee->name,
                        'email' => $payment->payee->email,
                    ] : null,
                ],
            ];
        });

        // ── 4. Merge and sort ────────────────────────────────────────────
        // Convert ledger transactions to arrays
        $ledgerArrays = $ledgerTxs->map(function ($tx) {
            $data = $tx->toArray();
            $freelancer = null;
            if ($tx->payment && $tx->payment->payee) {
                $freelancer = $tx->payment->payee;
            } elseif ($tx->payment && $tx->payment->milestone && $tx->payment->milestone->contract && $tx->payment->milestone->contract->freelancer) {
                $freelancer = $tx->payment->milestone->contract->freelancer;
            }
            if ($freelancer) {
                $data['freelancer'] = [
                    'id'    => $freelancer->id,
                    'name'  => $freelancer->name,
                    'email' => $freelancer->email,
                ];
            }
            return $data;
        });

        $all = $ledgerArrays->concat($virtualTxs)
            ->sortByDesc('created_at')
            ->values();

        // ── 5. Apply filters ─────────────────────────────────────────────
        if ($request->filled('type')) {
            $all = $all->filter(fn ($tx) => $tx['type'] === $request->input('type'));
        }

        // ── 6. Paginate manually ─────────────────────────────────────────
        $perPage = 20;
        $page = (int) $request->input('page', 1);
        $total = $all->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->sendResponse(
            $items,
            'Transactions retrieved.',
            200,
            [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'total'        => $total,
            ]
        );
    }

    /**
     * Remove a transaction entry from the authenticated user's history.
     *
     * Financial records are never hard-deleted (the ledger must stay intact), so
     * this soft-deletes the ledger row. Payment-derived "virtual" entries are
     * hidden by recording a soft-deleted marker against the payment so they do
     * not reappear when the list is rebuilt.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        // ── Virtual entry (payment without its own ledger row) ───────────
        if (str_starts_with($id, 'virtual_')) {
            $paymentId = (int) substr($id, strlen('virtual_'));
            $payment = Payment::find($paymentId);

            if (!$payment || !in_array($user->id, [$payment->payer_id, $payment->payee_id])) {
                return $this->sendError('Transaction entry not found.', [], 404);
            }

            // Record a soft-deleted marker (if none exists) so the payment no
            // longer shows up in this user's history as a virtual transaction.
            $marker = Transaction::withTrashed()
                ->where('user_id', $user->id)
                ->where('payment_id', $payment->id)
                ->first();

            if (!$marker) {
                $marker = new Transaction([
                    'reference'  => Transaction::generateReference(),
                    'user_id'    => $user->id,
                    'payment_id' => $payment->id,
                    'direction'  => Transaction::DIR_DEBIT,
                    'type'       => Transaction::TYPE_ADJUSTMENT,
                    'amount'     => 0,
                    'status'     => Transaction::STATUS_COMPLETED,
                ]);
                $marker->deleted_at = now();
                $marker->save();
            }

            return $this->sendResponse(null, 'History entry deleted.');
        }

        // ── Real ledger row ──────────────────────────────────────────────
        $transaction = Transaction::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$transaction) {
            return $this->sendError('Transaction entry not found.', [], 404);
        }

        if (!$transaction->isDeletable()) {
            return $this->sendError('Pending transactions cannot be deleted yet.', [], 422);
        }

        $transaction->delete();

        return $this->sendResponse(null, 'History entry deleted.');
    }

    /**
     * Extract freelancer info from a payment's relationships.
     */
    private function extractFreelancer(Payment $payment): ?array
    {
        // Direct payee
        if ($payment->payee) {
            return [
                'id'    => $payment->payee->id,
                'name'  => $payment->payee->name,
                'email' => $payment->payee->email,
            ];
        }

        // Via milestone → contract → freelancer
        if ($payment->milestone && $payment->milestone->contract && $payment->milestone->contract->freelancer) {
            $fl = $payment->milestone->contract->freelancer;
            return [
                'id'    => $fl->id,
                'name'  => $fl->name,
                'email' => $fl->email,
            ];
        }

        return null;
    }
}
