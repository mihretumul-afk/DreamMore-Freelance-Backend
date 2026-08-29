<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EmployerBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BudgetController extends BaseApiController
{
    /**
     * Get employer's budget settings and current spending.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $budget = EmployerBudget::firstOrCreate(
            ['user_id' => $user->id],
            [
                'monthly_limit'   => 0,
                'alert_threshold' => 80,
                'alerts_enabled'  => true,
                'currency'        => 'ETB',
            ]
        );

        $currentSpending = $budget->getCurrentMonthSpending();
        $remaining = $budget->getRemainingBudget();
        $usagePercentage = $budget->getUsagePercentage();

        return $this->sendResponse([
            'budget' => [
                'id'                => $budget->id,
                'monthly_limit'     => (float) $budget->monthly_limit,
                'alert_threshold'   => (float) $budget->alert_threshold,
                'alerts_enabled'    => $budget->alerts_enabled,
                'currency'          => $budget->currency,
            ],
            'spending' => [
                'current_month'     => $currentSpending,
                'remaining'         => $remaining,
                'usage_percentage'  => $usagePercentage,
                'is_exceeded'       => $budget->isExceeded(),
                'should_alert'      => $budget->shouldAlert(),
                'month'             => now()->format('F Y'),
            ],
        ], 'Budget retrieved.');
    }

    /**
     * Update employer's budget settings.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'monthly_limit'   => 'required|numeric|min:0|max:10000000',
            'alert_threshold' => 'nullable|numeric|min:10|max:100',
            'alerts_enabled'  => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors()->toArray(), 422);
        }

        $budget = EmployerBudget::firstOrCreate(
            ['user_id' => $user->id],
            [
                'monthly_limit'   => 0,
                'alert_threshold' => 80,
                'alerts_enabled'  => true,
                'currency'        => 'ETB',
            ]
        );

        $budget->update([
            'monthly_limit'   => (float) $request->input('monthly_limit'),
            'alert_threshold' => (float) ($request->input('alert_threshold') ?? 80),
            'alerts_enabled'  => $request->boolean('alerts_enabled', true),
        ]);

        $currentSpending = $budget->getCurrentMonthSpending();
        $remaining = $budget->getRemainingBudget();
        $usagePercentage = $budget->getUsagePercentage();

        return $this->sendResponse([
            'budget' => [
                'id'                => $budget->id,
                'monthly_limit'     => (float) $budget->monthly_limit,
                'alert_threshold'   => (float) $budget->alert_threshold,
                'alerts_enabled'    => $budget->alerts_enabled,
                'currency'          => $budget->currency,
            ],
            'spending' => [
                'current_month'     => $currentSpending,
                'remaining'         => $remaining,
                'usage_percentage'  => $usagePercentage,
                'is_exceeded'       => $budget->isExceeded(),
                'should_alert'      => $budget->shouldAlert(),
                'month'             => now()->format('F Y'),
            ],
        ], 'Budget updated successfully.');
    }
}
