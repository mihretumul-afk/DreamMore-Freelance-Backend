<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
                'avatar' => $this->user->avatar,
                'phone' => $this->user->phone,
            ],
            'headline' => $this->headline,
            'overview' => $this->overview,
            'hourly_rate' => $this->hourly_rate ? (float) $this->hourly_rate : null,
            'experience_level' => $this->experience_level,
            'location' => $this->location,
            'github_url' => $this->github_url,
            'linkedin_url' => $this->linkedin_url,
            'website' => $this->website,
            'total_earnings' => (float) ($this->total_earnings ?? 0),
            'completed_jobs_count' => (int) ($this->completed_jobs_count ?? 0),
            'rating' => $this->rating ? (float) $this->rating : 0.0,
            'availability_status' => $this->availability_status ?? 'available',
            'skills' => $this->whenLoaded('skills', function () {
                return $this->skills->map(function ($skill) {
                    return [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'slug' => $skill->slug,
                        'years_of_experience' => $skill->pivot->years_of_experience ?? null,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
