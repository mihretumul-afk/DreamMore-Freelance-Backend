<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'platform_name'        => ['nullable', 'string', 'max:255'],
            'platform_description' => ['nullable', 'string', 'max:2000'],
            'support_email'        => ['nullable', 'email', 'max:255'],
            'default_currency'     => ['nullable', 'string', 'max:10'],
            'min_proposal_amount'  => ['nullable', 'string'],
            'max_proposal_amount'  => ['nullable', 'string'],
            'registration_open'    => ['nullable', 'string', 'in:true,false'],
            'maintenance_mode'     => ['nullable', 'string', 'in:true,false'],
        ];
    }

    public function messages(): array
    {
        return [
            'support_email.email'    => 'Please provide a valid email address.',
            'default_currency.max'   => 'Currency code must be 10 characters or fewer.',
            'registration_open.in'   => 'Registration open must be "true" or "false".',
            'maintenance_mode.in'    => 'Maintenance mode must be "true" or "false".',
            'platform_name.max'      => 'Platform name must be 255 characters or fewer.',
            'platform_description.max' => 'Platform description must be 2000 characters or fewer.',
        ];
    }
}
