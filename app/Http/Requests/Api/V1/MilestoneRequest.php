<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MilestoneRequest extends FormRequest
{
    /**
     * Authorization is enforced by the controller (role + contract ownership).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for creating/updating a milestone.
     * Amounts are in Ethiopian Birr (ETB).
     */
    public function rules(): array
    {
        $required = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => [$required, 'numeric', 'min:1', 'max:99999999.99'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
