<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedJobResource extends JsonResource
{
    /**
     * Transform the saved job into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'job_id' => $this->job_id,
            'saved_at' => $this->created_at?->toIso8601String(),
            'job' => $this->whenLoaded('job', function () {
                return [
                    'id' => $this->job->id,
                    'title' => $this->job->title,
                    'slug' => $this->job->slug,
                    'description' => $this->job->description,
                    'budget_type' => $this->job->budget_type,
                    'min_budget' => (float) $this->job->min_budget,
                    'max_budget' => (float) $this->job->max_budget,
                    'currency' => $this->job->currency,
                    'experience_level' => $this->job->experience_level,
                    'location_type' => $this->job->location_type,
                    'location' => $this->job->location,
                    'status' => $this->job->status,
                    'proposals_count' => $this->job->proposals_count,
                    'deadline' => $this->job->deadline?->toIso8601String(),
                    'published_at' => $this->job->published_at?->toIso8601String(),
                    'created_at' => $this->job->created_at?->toIso8601String(),
                    'category' => $this->job->category ? [
                        'id' => $this->job->category->id,
                        'name' => $this->job->category->name,
                        'slug' => $this->job->category->slug,
                    ] : null,
                    'skills' => $this->job->skills->map(fn ($skill) => [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'slug' => $skill->slug,
                    ])->values(),
                    'employer' => $this->job->employer ? [
                        'id' => $this->job->employer->id,
                        'name' => $this->job->employer->name,
                        'avatar' => $this->job->employer->avatar,
                    ] : null,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
