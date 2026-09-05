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
            'default_currency'     => ['nullable', 'string', 'max:10'],

            // Contact page details (footer Contact button → /contact)
            'contact_email'    => ['nullable', 'email', 'max:255'],
            'contact_phone'    => ['nullable', 'string', 'max:100'],
            'contact_location' => ['nullable', 'string', 'max:255'],
            'contact_hours'    => ['nullable', 'string', 'max:255'],
            // Additional contact entries added by admins: [{ type, label, value }]
            'contact_extras'       => ['nullable', 'array', 'max:20'],
            'contact_extras.*.type'  => ['required', 'string', 'in:email,phone,website,social'],
            'contact_extras.*.label' => ['nullable', 'string', 'max:60'],
            'contact_extras.*.value' => ['required', 'string', 'max:255'],
            // Social media links shown in the footer: [{ platform, url }]
            'social_links'         => ['nullable', 'array', 'max:20'],
            'social_links.*.platform' => ['required', 'string', 'in:github,telegram,facebook,instagram,linkedin,youtube,x,whatsapp,website'],
            'social_links.*.url'      => ['nullable', 'string', 'max:255'],
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
            'contact_email.email'    => 'Please provide a valid contact email address.',
            'contact_extras.max'     => 'You can add up to 20 additional contacts.',
            'contact_extras.*.type.in'    => 'Each extra contact must use a supported type.',
            'contact_extras.*.value.required' => 'Each extra contact needs a value.',
            'social_links.max'           => 'You can add up to 20 social media links.',
            'social_links.*.platform.in' => 'Each social link must use a supported platform.',
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
