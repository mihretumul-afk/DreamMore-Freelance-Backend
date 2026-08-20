<?php

namespace App\Http\Resources;

use App\Models\Credential;
use App\Models\Review;
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
        $isOwnProfile = $request->user() && $request->user()->id === $this->user_id;
        $isAdmin = $request->user() && $request->user()->role === 'admin';

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
            'review_count' => Review::where('reviewee_id', $this->user_id)->count(),
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
            // Show verified credentials metadata publicly (no file paths)
            // Includes trust level: Dream More LMS vs External verified
            'verified_credentials' => Credential::where('user_id', $this->user_id)
                ->where('status', 'approved')
                ->select(
                    'id', 'title', 'type', 'issuing_organization', 'certificate_identifier',
                    'description', 'issue_date', 'expiry_date',
                    'verification_source', 'lms_course_name', 'lms_course_id', 'auto_verified'
                )
                ->orderByDesc('created_at')
                ->get(),
            // Show own credentials only to the owner or admin
            'credentials' => ($isOwnProfile || $isAdmin)
                ? Credential::where('user_id', $this->user_id)
                    ->orderByDesc('created_at')
                    ->get()
                : null,
            // Recent reviews (public, no sensitive data)
            'reviews' => Review::where('reviewee_id', $this->user_id)
                ->with(['reviewer:id,name,avatar', 'contract:id,title'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'reviewer_name' => $review->reviewer->name,
                        'reviewer_avatar' => $review->reviewer->avatar,
                        'contract_title' => $review->contract->title,
                        'created_at' => $review->created_at->toISOString(),
                    ];
                }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
