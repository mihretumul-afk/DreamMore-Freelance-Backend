<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Review;
use App\Models\Verification;
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
                $profile = $this->freelancer->freelancerProfile;
                $verification = Verification::where('user_id', $this->freelancer_id)
                    ->orderByDesc('created_at')
                    ->first();

                return [
                    'id' => $this->freelancer->id,
                    'name' => $this->freelancer->name,
                    'avatar' => $this->freelancer->avatar,
                    'headline' => $profile?->headline,
                    'experience_level' => $profile?->experience_level,
                    'rating' => $profile?->rating ? (float) $profile->rating : 0.0,
                    'review_count' => Review::where('reviewee_id', $this->freelancer_id)->count(),
                    'verification_status' => $verification?->status ?? 'unverified',
                    'skills' => $profile && $profile->relationLoaded('skills')
                        ? $profile->skills->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                        : ($profile ? $profile->skills()->select('skills.id', 'skills.name')->get() : []),
                ];
            }),
            'portfolio_items' => $this->whenLoaded('portfolioItems', function () {
                return $this->portfolioItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'project_url' => $item->project_url,
                        'image_url' => $item->image_url,
                    ];
                });
            }),
            'contract' => $this->whenLoaded('contract', function () {
                return $this->contract ? [
                    'id' => $this->contract->id,
                    'title' => $this->contract->title,
                    'status' => $this->contract->status,
                    'budget_type' => $this->contract->budget_type,
                    'agreed_rate' => (float) $this->contract->agreed_rate,
                    'total_amount' => (float) $this->contract->total_amount,
                    'start_date' => $this->contract->start_date?->toIso8601String(),
                    'end_date' => $this->contract->end_date?->toIso8601String(),
                    'created_at' => $this->contract->created_at?->toIso8601String(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
