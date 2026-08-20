<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private function createCompletedContract(): array
    {
        $employer = User::create([
            'name' => 'Review Employer',
            'email' => 'review_employer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);

        $freelancer = User::create([
            'name' => 'Review Freelancer',
            'email' => 'review_freelancer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        FreelancerProfile::create(['user_id' => $freelancer->id]);

        $job = Job::create([
            'employer_id' => $employer->id,
            'title' => 'Review Test Job',
            'slug' => 'review-test-job-' . uniqid(),
            'description' => 'A test job for reviews',
            'budget_type' => 'fixed',
            'min_budget' => 1000,
            'max_budget' => 5000,
            'currency' => 'ETB',
            'status' => 'in_progress',
        ]);

        $proposal = Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I can do this.',
            'bid_amount' => 3000,
            'estimated_duration' => '2 weeks',
            'currency' => 'ETB',
            'status' => 'accepted',
        ]);

        $contract = Contract::create([
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'title' => $job->title,
            'budget_type' => 'fixed',
            'agreed_rate' => 3000,
            'total_amount' => 3000,
            'status' => 'completed',
            'end_date' => now(),
        ]);

        return compact('employer', 'freelancer', 'contract', 'job');
    }

    public function test_employer_can_review_completed_contract(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 5,
                'comment' => 'Excellent work! Highly recommended.',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Review submitted successfully.',
            ]);

        $this->assertDatabaseHas('reviews', [
            'contract_id' => $contract->id,
            'reviewer_id' => $employer->id,
            'reviewee_id' => $freelancer->id,
            'rating' => 5,
        ]);
    }

    public function test_freelancer_can_review_completed_contract(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 4,
                'comment' => 'Good employer to work with.',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('reviews', [
            'contract_id' => $contract->id,
            'reviewer_id' => $freelancer->id,
            'reviewee_id' => $employer->id,
            'rating' => 4,
        ]);
    }

    public function test_cannot_review_incomplete_contract(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        // Change contract status to active
        $contract->update(['status' => 'active', 'end_date' => null]);

        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 5,
                'comment' => 'Great work!',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Reviews can only be submitted for completed contracts.',
            ]);
    }

    public function test_cannot_review_unrelated_contract(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);

        $token = $stranger->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 5,
                'comment' => 'Spam review',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_review_self(): void
    {
        // This shouldn't happen with contract logic, but test the safety check
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        // Manually create a contract where employer reviews themselves (shouldn't happen)
        $contract->update(['freelancer_id' => $employer->id]);

        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 5,
                'comment' => 'Self review',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You cannot review yourself.',
            ]);
    }

    public function test_duplicate_review_blocked(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        // First review
        Review::create([
            'contract_id' => $contract->id,
            'reviewer_id' => $employer->id,
            'reviewee_id' => $freelancer->id,
            'reviewer_role' => 'employer',
            'rating' => 5,
            'comment' => 'First review',
        ]);

        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 4,
                'comment' => 'Second review attempt',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You have already reviewed this contract.',
            ]);
    }

    public function test_review_validation_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rating', 'comment']);
    }

    public function test_rating_aggregation_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();
        $token = $employer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/contracts/{$contract->id}/review", [
                'rating' => 4,
                'comment' => 'Good work',
            ])->assertStatus(201);

        $profile = FreelancerProfile::where('user_id', $freelancer->id)->first();
        $profile->refresh();

        $this->assertEquals(4.0, (float) $profile->rating);
    }

    public function test_public_profile_returns_reviews(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        Review::create([
            'contract_id' => $contract->id,
            'reviewer_id' => $employer->id,
            'reviewee_id' => $freelancer->id,
            'reviewer_role' => 'employer',
            'rating' => 5,
            'comment' => 'Excellent work!',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$freelancer->id}");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('reviews', $data);
        $this->assertArrayHasKey('review_count', $data);
        $this->assertEquals(1, $data['review_count']);
        $this->assertCount(1, $data['reviews']);
        $this->assertEquals(5, $data['reviews'][0]['rating']);
    }

    public function test_unauthenticated_review_access_blocked(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        $response = $this->postJson("/api/v1/contracts/{$contract->id}/review", [
            'rating' => 5,
            'comment' => 'Great work!',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_reviews_endpoint_works(): void
    {
        ['employer' => $employer, 'freelancer' => $freelancer, 'contract' => $contract] = $this->createCompletedContract();

        Review::create([
            'contract_id' => $contract->id,
            'reviewer_id' => $employer->id,
            'reviewee_id' => $freelancer->id,
            'reviewer_role' => 'employer',
            'rating' => 5,
            'comment' => 'Excellent work!',
        ]);

        $response = $this->getJson("/api/v1/users/{$freelancer->id}/reviews");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    [
                        'rating' => 5,
                        'comment' => 'Excellent work!',
                    ],
                ],
            ]);
    }
}
