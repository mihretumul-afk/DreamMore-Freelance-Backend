<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFreelancerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->role === 'freelancer' || $this->user()->role === 'admin');
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'headline' => ['nullable', 'string', 'max:255'],
            'overview' => ['nullable', 'string', 'max:5000'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'experience_level' => ['nullable', 'string', 'in:entry,intermediate,expert'],
            'location' => ['nullable', 'string', 'max:255'],
            'github_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'availability_status' => ['nullable', 'string', 'in:available,busy,not_available'],
            'skills' => ['nullable', 'array'],
            'skills.*.id' => ['required_with:skills', 'exists:skills,id'],
            'skills.*.years_of_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }
}
