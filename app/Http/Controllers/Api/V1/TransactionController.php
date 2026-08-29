<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends BaseApiController
{
    /**
     * List user's transactions (financial ledger).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $request->user()->id)
            ->with(['payment.payee' => function ($q) {
                $q->select('id', 'name', 'email');
            }])
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $transactions = $query->paginate(20);

        // Transform transactions to include freelancer info from payment
        $items = collect($transactions->items())->map(function ($tx) {
            $data = $tx->toArray();
            if ($tx->payment && $tx->payment->payee) {
                $data['freelancer'] = [
                    'id'    => $tx->payment->payee->id,
                    'name'  => $tx->payment->payee->name,
                    'email' => $tx->payment->payee->email,
                ];
            }
            return $data;
        });

        return $this->sendResponse(
            $items,
            'Transactions retrieved.',
            200,
            [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ]
        );
    }
}
