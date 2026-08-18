<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MilestoneSubmissionRequest extends FormRequest
{
    /**
     * Authorization is enforced by the controller (role + contract ownership).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Submitting a milestone does not require a payload.
     */
    public function rules(): array
    {
        return [];
    }
}
