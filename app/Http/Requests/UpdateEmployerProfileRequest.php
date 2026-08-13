<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->role === 'employer' || $this->user()->role === 'admin');
    }

    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'company_size' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
