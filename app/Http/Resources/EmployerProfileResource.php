<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployerProfileResource extends JsonResource
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
                'status' => $this->user->status,
                'avatar' => $this->user->avatar,
                'phone' => $this->user->phone,
                'bio' => $this->user->bio,
            ],
            'company_name' => $this->company_name,
            'company_description' => $this->company_description,
            'website' => $this->website,
            'industry' => $this->industry,
            'company_size' => $this->company_size,
            'location' => $this->location,
            'total_spent' => (float) ($this->total_spent ?? 0),
            'posted_jobs_count' => (int) ($this->posted_jobs_count ?? 0),
            'rating' => $this->rating ? (float) $this->rating : 0.0,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
