<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin\TransactionController — admin view of all platform transactions.
 *
 * All routes require: auth:sanctum + role:admin + permission:transactions.view
 */
class TransactionController extends BaseApiController
{
    /**
     * GET /admin/transactions
     * Paginated, filterable list of all platform transactions.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('transactions.view')) {
            return $this->sendForbidden('You do not have permission to view transactions.');
        }

        $query = Transaction::with([
            'user:id,name,email,role',
            'payment:id,reference,type,status',
            'contract:id,title',
            'milestone:id,title',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
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
                  ->orWhereHas('user', fn ($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            });
        }

        $transactions = $query->paginate(25);

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
     * GET /admin/transactions/{transaction}
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if (!$request->user()->hasPermission('transactions.view')) {
            return $this->sendForbidden('You do not have permission to view transactions.');
        }

        $transaction->load([
            'user:id,name,email,role',
            'payment.paymentMethod:id,type,display_label',
            'contract:id,title,status',
            'milestone:id,title,amount,status',
        ]);

        return $this->sendResponse($transaction, 'Transaction retrieved.');
    }

    /**
     * GET /admin/transactions/export
     * Returns all transactions as a flat array for CSV export.
     * Requires transactions.export permission.
     */
    public function export(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('transactions.export')) {
            return $this->sendForbidden('You do not have permission to export transactions.');
        }

        $query = Transaction::with([
            'user:id,name,email',
            'payment:id,reference',
            'contract:id,title',
            'milestone:id,title',
        ])->orderByDesc('created_at');

        // Allow date filters on exports too.
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Cap at 5000 rows for foundation export (streaming in Stage 2).
        $rows = $query->limit(5000)->get()->map(fn ($t) => [
            'id'             => $t->id,
            'reference'      => $t->reference,
            'payment_ref'    => $t->payment?->reference,
            'user_name'      => $t->user?->name,
            'user_email'     => $t->user?->email,
            'direction'      => $t->direction,
            'type'           => $t->type,
            'amount'         => $t->amount,
            'fee'            => $t->fee,
            'currency'       => $t->currency,
            'status'         => $t->status,
            'description'    => $t->description,
            'contract_title' => $t->contract?->title,
            'milestone_title'=> $t->milestone?->title,
            'created_at'     => $t->created_at?->toIso8601String(),
        ]);

        return $this->sendResponse($rows, 'Export ready.', 200, [
            'count' => $rows->count(),
            'note'  => $rows->count() >= 5000 ? 'Results capped at 5000. Use date filters for larger exports.' : null,
        ]);
    }
}
