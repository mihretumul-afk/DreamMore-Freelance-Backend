<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AdminSetting;
use Illuminate\Foundation\Http\FormRequest;

class ProposalRequest extends FormRequest
{
    /**
     * Authorization is enforced by the controller (role + proposal ownership).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for creating/updating a proposal.
     * Amounts use the platform's default currency; min/max are read from
     * AdminSetting when set (empty string = no restriction).
     */
    public function rules(): array
    {
        $required = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        $minAmount = AdminSetting::getValue('min_proposal_amount', '', 'string');
        $maxAmount = AdminSetting::getValue('max_proposal_amount', '', 'string');

        $bidRules = [$required, 'numeric', 'min:1', 'max:99999999.99'];

        if ($minAmount !== '' && is_numeric($minAmount)) {
            $bidRules[] = 'min:' . (float) $minAmount;
        }

        if ($maxAmount !== '' && is_numeric($maxAmount)) {
            $bidRules[] = 'max:' . (float) $maxAmount;
        }

        return [
            'cover_letter' => [$required, 'string', 'max:5000'],
            'bid_amount' => $bidRules,
            'currency' => ['sometimes', 'string', 'size:3'],
            'estimated_duration' => [$required, 'string', 'max:255'],
            'portfolio_item_ids' => ['nullable', 'array'],
            'portfolio_item_ids.*' => ['integer', 'exists:portfolio_items,id'],
        ];
    }

    /**
     * Custom validation messages for proposal limits.
     */
    public function messages(): array
    {
        $minAmount = AdminSetting::getValue('min_proposal_amount', '', 'string');
        $maxAmount = AdminSetting::getValue('max_proposal_amount', '', 'string');
        $currency = AdminSetting::getValue('default_currency', 'ETB', 'string');

        $messages = [];

        if ($minAmount !== '' && is_numeric($minAmount)) {
            $messages['bid_amount.min'] = "The bid amount must be at least {$currency} " . number_format((float) $minAmount, 2) . ".";
        }

        if ($maxAmount !== '' && is_numeric($maxAmount)) {
            $messages['bid_amount.max'] = "The bid amount cannot exceed {$currency} " . number_format((float) $maxAmount, 2) . ".";
        }

        return $messages;
    }
}
