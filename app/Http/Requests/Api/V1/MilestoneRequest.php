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
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => [
                'file',
                'max:102400', // 100MB max per file
                'mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov,mkv,avi,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,zip,tar,gz,rar,7z,fig,xd,sketch,psd,ai,wav,mp3,ogg,flac,aac',
            ],
        ];
    }

    /**
     * Custom validation error messages.
     */
    public function messages(): array
    {
        return [
            'attachments.*.mimes' => 'File type is not supported.',
            'attachments.*.max' => 'File is too large. Maximum allowed size is 100MB per file.',
        ];
    }
}
