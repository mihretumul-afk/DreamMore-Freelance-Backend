<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Contract;
use App\Models\FreelancerProfile;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends BaseApiController
{
    /**
     * Submit a review for a completed contract.
     * Only the employer or freelancer who participated in the contract can review.
     */
    public function store(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        // Only freelancers and employers can review
        if ($user->role !== 'freelancer' && $user->role !== 'employer') {
            return $this->sendForbidden('Only freelancers and employers can submit reviews.');
        }

        // Contract must be completed
        if ($contract->status !== 'completed') {
            return $this->sendError('Reviews can only be submitted for completed contracts.', [], 422);
        }

        // User must be a participant in this contract
        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You can only review contracts you participated in.');
        }

        // Determine reviewer and reviewee
        $isEmployer = $user->id === $contract->employer_id;
        $revieweeId = $isEmployer ? $contract->freelancer_id : $contract->employer_id;
        $reviewerRole = $isEmployer ? 'employer' : 'freelancer';

        // Validate input
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:2000',
        ]);

        // Prevent duplicate reviews for the same contract by the same reviewer
        $existingReview = Review::where('contract_id', $contract->id)
            ->where('reviewer_id', $user->id)
            ->exists();

        if ($existingReview) {
            return $this->sendError('You have already reviewed this contract.', [], 422);
        }

        // Prevent self-review (shouldn't happen with contract logic, but safety check)
        if ($user->id === $revieweeId) {
            return $this->sendError('You cannot review yourself.', [], 422);
        }

        $review = DB::transaction(function () use ($contract, $user, $revieweeId, $reviewerRole, $validated) {
            $review = Review::create([
                'contract_id' => $contract->id,
                'reviewer_id' => $user->id,
                'reviewee_id' => $revieweeId,
                'reviewer_role' => $reviewerRole,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
            ]);

            // Update the reviewee's average rating
            $this->updateAverageRating($revieweeId);

            return $review;
        });

        // Notify the reviewee
        NotificationService::reviewReceived(
            $revieweeId,
            $user->name,
            $validated['rating'],
            $contract->id
        );

        $review->load(['reviewer', 'reviewee', 'contract']);

        return $this->sendResponse($review, 'Review submitted successfully.', 201);
    }

    /**
     * Show a single review.
     */
    public function show(Review $review): JsonResponse
    {
        $review->load(['reviewer', 'reviewee', 'contract']);

        return $this->sendResponse($review, 'Review retrieved successfully.');
    }

    /**
     * Get reviews for a user (public).
     */
    public function userReviews(string $userId): JsonResponse
    {
        $reviews = Review::where('reviewee_id', $userId)
            ->with(['reviewer:id,name,avatar', 'contract:id,title'])
            ->orderByDesc('created_at')
            ->paginate(15);

        // Calculate average rating
        $avgRating = Review::where('reviewee_id', $userId)->avg('rating');
        $reviewCount = Review::where('reviewee_id', $userId)->count();

        return $this->sendResponse(
            $reviews->items(),
            'Reviews retrieved successfully.',
            200,
            [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'average_rating' => $avgRating ? round($avgRating, 1) : null,
                'review_count' => $reviewCount,
            ]
        );
    }

    /**
     * Recalculate and update a user's average rating in their freelancer profile.
     */
    private function updateAverageRating(int $userId): void
    {
        $avgRating = Review::where('reviewee_id', $userId)->avg('rating');
        $reviewCount = Review::where('reviewee_id', $userId)->count();

        // Update freelancer profile if it exists
        $profile = FreelancerProfile::where('user_id', $userId)->first();
        if ($profile) {
            $profile->update([
                'rating' => $avgRating ? round($avgRating, 2) : 0,
            ]);
        }
    }
}
