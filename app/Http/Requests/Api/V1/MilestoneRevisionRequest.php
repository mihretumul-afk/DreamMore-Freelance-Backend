<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MilestoneRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision_note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision_note.required' => 'Please provide revision feedback instructions.',
            'revision_note.min' => 'Revision instructions must be at least 3 characters long.',
            'revision_note.max' => 'Revision instructions may not exceed 2000 characters.',
        ];
    }
}
