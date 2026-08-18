<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobResource extends JsonResource
{
    /**
     * Transform the job into an array.
     * Amounts are in Ethiopian Birr (ETB).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employer_id' => $this->employer_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'budget_type' => $this->budget_type,
            'min_budget' => (float) $this->min_budget,
            'max_budget' => (float) $this->max_budget,
            'currency' => $this->currency,
            'experience_level' => $this->experience_level,
            'location_type' => $this->location_type,
            'location' => $this->location,
            'status' => $this->status,
            'proposals_count' => $this->proposals_count,
            'deadline' => $this->deadline?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'category' => $this->whenLoaded('category', function () {
                return $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ] : null;
            }),
            'skills' => $this->whenLoaded('skills', function () {
                return $this->skills->map(fn ($skill) => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'slug' => $skill->slug,
                ])->values();
            }),
            'employer' => $this->whenLoaded('employer', function () {
                return [
                    'id' => $this->employer->id,
                    'name' => $this->employer->name,
                    'avatar' => $this->employer->avatar,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
