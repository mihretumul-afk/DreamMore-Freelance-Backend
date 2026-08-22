<?php

namespace App\Http\Requests\Api\V1;

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
     * Amounts are in Ethiopian Birr (ETB); status is system-managed.
     */
    public function rules(): array
    {
        $required = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'cover_letter' => [$required, 'string', 'max:5000'],
            'bid_amount' => [$required, 'numeric', 'min:1', 'max:99999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'estimated_duration' => [$required, 'string', 'max:255'],
            'portfolio_item_ids' => ['nullable', 'array'],
            'portfolio_item_ids.*' => ['integer', 'exists:portfolio_items,id'],
        ];
    }
}
