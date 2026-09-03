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

            // Featured Listing settings
            'featured_jobs_enabled'          => ['nullable', 'string', 'in:true,false'],
            'featured_profiles_enabled'      => ['nullable', 'string', 'in:true,false'],
            'featured_job_price'             => ['nullable', 'string', 'numeric', 'min:0'],
            'featured_job_duration_days'     => ['nullable', 'string', 'integer', 'min:1', 'max:90'],
            'featured_profile_price'         => ['nullable', 'string', 'numeric', 'min:0'],
            'featured_profile_duration_days' => ['nullable', 'string', 'integer', 'min:1', 'max:90'],
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
            'featured_jobs_enabled.in'          => 'Featured jobs enabled must be "true" or "false".',
            'featured_profiles_enabled.in'      => 'Featured profiles enabled must be "true" or "false".',
            'featured_job_price.numeric'        => 'Featured job price must be a number.',
            'featured_job_price.min'            => 'Featured job price must be at least 0.',
            'featured_job_duration_days.integer' => 'Featured job duration must be a whole number.',
            'featured_job_duration_days.min'     => 'Featured job duration must be at least 1 day.',
            'featured_job_duration_days.max'     => 'Featured job duration cannot exceed 90 days.',
            'featured_profile_price.numeric'     => 'Featured profile price must be a number.',
            'featured_profile_price.min'         => 'Featured profile price must be at least 0.',
            'featured_profile_duration_days.integer' => 'Featured profile duration must be a whole number.',
            'featured_profile_duration_days.min' => 'Featured profile duration must be at least 1 day.',
            'featured_profile_duration_days.max' => 'Featured profile duration cannot exceed 90 days.',
        ];
    }
}
