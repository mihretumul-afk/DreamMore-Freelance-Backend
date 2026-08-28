<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\EscrowTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueReportController extends BaseApiController
{
    /**
     * Admin revenue summary report.
     */
    public function summary(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->sendForbidden('Only administrators can view revenue reports.');
        }

        $totalPlatformFeeRevenue = (float) EscrowTransaction::where('type', 'platform_fee')
            ->where('status', 'completed')
            ->sum('amount');

        $thisMonthFeeRevenue = (float) EscrowTransaction::where('type', 'platform_fee')
            ->where('status', 'completed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        $totalPaidToFreelancers = (float) EscrowTransaction::where('type', 'release')
            ->where('status', 'completed')
            ->sum('amount');

        $totalEscrowHeld = (float) EscrowTransaction::where('type', 'escrow_hold')
            ->where('status', 'completed')
            ->sum('amount');

        $totalWithdrawals = (float) EscrowTransaction::where('type', 'withdrawal')
            ->where('status', 'completed')
            ->sum('amount');

        return $this->sendResponse([
            'total_platform_fee_revenue' => $totalPlatformFeeRevenue,
            'this_month_fee_revenue'     => $thisMonthFeeRevenue,
            'total_paid_to_freelancers'  => $totalPaidToFreelancers,
            'total_escrow_held'          => $totalEscrowHeld,
            'total_withdrawals'          => $totalWithdrawals,
            'currency'                   => 'ETB',
        ], 'Revenue report summary retrieved successfully.');
    }
}
