<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProposalResource extends JsonResource
{
    /**
     * Transform the proposal into an array.
     * Amounts are in Ethiopian Birr (ETB).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'freelancer_id' => $this->freelancer_id,
            'cover_letter' => $this->cover_letter,
            'bid_amount' => (float) $this->bid_amount,
            'currency' => $this->currency,
            'estimated_duration' => $this->estimated_duration,
            'status' => $this->status,
            'job' => $this->whenLoaded('job', function () {
                return [
                    'id' => $this->job->id,
                    'title' => $this->job->title,
                    'slug' => $this->job->slug,
                ];
            }),
            'freelancer' => $this->whenLoaded('freelancer', function () {
                return [
                    'id' => $this->freelancer->id,
                    'name' => $this->freelancer->name,
                    'avatar' => $this->freelancer->avatar,
                ];
            }),
            'contract' => $this->whenLoaded('contract', function () {
                return $this->contract ? [
                    'id' => $this->contract->id,
                    'status' => $this->contract->status,
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
