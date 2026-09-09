<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Credential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerResource extends JsonResource
{
    /**
     * Transform a freelancer profile for public discovery.
     * Only public profile data is exposed — no email, phone or earnings.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar' => $this->user->avatar,
                ];
            }),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return $this->category
                    ? ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]
                    : null;
            }),
            'headline' => $this->headline,
            'overview' => $this->overview,
            'hourly_rate' => $this->hourly_rate ? (float) $this->hourly_rate : null,
            'experience_level' => $this->experience_level,
            'location' => $this->location,
            'availability_status' => $this->availability_status ?? 'available',
            'rating' => $this->rating ? (float) $this->rating : 0.0,
            'completed_jobs_count' => (int) ($this->completed_jobs_count ?? 0),
            'skills' => $this->whenLoaded('skills', function () {
                return $this->skills->map(function ($skill) {
                    return [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'slug' => $skill->slug,
                        'years_of_experience' => $skill->pivot->years_of_experience ?? null,
                    ];
                })->values();
            }),
            // Only show verified credential metadata publicly — no file paths
            'verified_credentials' => Credential::where('user_id', $this->user_id)
                ->where('status', 'approved')
                ->select('id', 'title', 'type', 'issuing_organization')
                ->orderByDesc('created_at')
                ->get(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
