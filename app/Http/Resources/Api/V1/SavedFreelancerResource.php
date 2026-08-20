<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedFreelancerResource extends JsonResource
{
    /**
     * Transform the saved freelancer into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'freelancer_profile_id' => $this->freelancer_profile_id,
            'saved_at' => $this->created_at?->toIso8601String(),
            'freelancer' => $this->whenLoaded('freelancerProfile', function () {
                return [
                    'id' => $this->freelancerProfile->id,
                    'user_id' => $this->freelancerProfile->user_id,
                    'user' => $this->freelancerProfile->user ? [
                        'id' => $this->freelancerProfile->user->id,
                        'name' => $this->freelancerProfile->user->name,
                        'avatar' => $this->freelancerProfile->user->avatar,
                    ] : null,
                    'headline' => $this->freelancerProfile->headline,
                    'overview' => $this->freelancerProfile->overview,
                    'hourly_rate' => $this->freelancerProfile->hourly_rate ? (float) $this->freelancerProfile->hourly_rate : null,
                    'experience_level' => $this->freelancerProfile->experience_level,
                    'location' => $this->freelancerProfile->location,
                    'availability_status' => $this->freelancerProfile->availability_status ?? 'available',
                    'rating' => $this->freelancerProfile->rating ? (float) $this->freelancerProfile->rating : 0.0,
                    'completed_jobs_count' => (int) ($this->freelancerProfile->completed_jobs_count ?? 0),
                    'skills' => $this->freelancerProfile->skills->map(function ($skill) {
                        return [
                            'id' => $skill->id,
                            'name' => $skill->name,
                            'slug' => $skill->slug,
                            'years_of_experience' => $skill->pivot->years_of_experience ?? null,
                        ];
                    })->values(),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
