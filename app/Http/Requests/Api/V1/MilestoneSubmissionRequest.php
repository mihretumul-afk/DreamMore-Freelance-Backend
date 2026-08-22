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
     * Rules for submitting milestone deliverables.
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:5000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['url', 'max:500'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => [
                'file',
                'max:51200', // 50MB max per file
                'mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov,mkv,avi,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,zip,tar,gz,rar,7z,fig,xd,sketch,psd,ai',
            ],
        ];
    }

    /**
     * Custom validation error messages.
     */
    public function messages(): array
    {
        return [
            'files.*.mimes' => 'File type is not supported.',
            'files.*.max' => 'File is too large. Maximum allowed size is 50MB per file.',
            'links.*.url' => 'Please provide valid URLs for external links.',
        ];
    }
}
