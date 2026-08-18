<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class JobRequest extends FormRequest
{
    /**
     * Authorization is enforced by the controller (role + job ownership).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for creating/updating a job.
     * Amounts are in Ethiopian Birr (ETB); status is system-managed.
     */
    public function rules(): array
    {
        $required = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string', 'max:5000'],
            'budget_type' => [$required, 'in:fixed,hourly'],
            'min_budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'max_budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'experience_level' => ['sometimes', 'in:entry,intermediate,expert'],
            'location_type' => ['sometimes', 'in:remote,onsite,hybrid'],
            'location' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['exists:skills,id'],
            'deadline' => ['nullable', 'date'],
        ];
    }

    /**
     * After validation: a max budget may never be lower than the min budget.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $min = $this->input('min_budget');
            $max = $this->input('max_budget');

            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add('max_budget', 'The max budget must be greater than or equal to the min budget.');
            }
        });
    }
}
