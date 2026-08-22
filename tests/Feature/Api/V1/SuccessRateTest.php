<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuccessRateTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private User $employer;
    private FreelancerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $this->profile = FreelancerProfile::create([
            'user_id' => $this->freelancer->id,
            'headline' => 'Senior Developer',
            'overview' => 'Experienced developer',
            'experience_level' => 'expert',
        ]);

        $this->employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);
    }

    private function createJob(): Job
    {
        return Job::create([
            'employer_id' => $this->employer->id,
            'title' => 'Sample Job ' . Str::random(5),
            'slug' => 'sample-job-' . Str::random(8),
            'description' => 'A test job',
            'budget_type' => 'fixed',
            'min_budget' => 1000,
            'max_budget' => 5000,
            'status' => 'open',
        ]);
    }

    private function createProposal(Job $job): Proposal
    {
        return Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $this->freelancer->id,
            'cover_letter' => 'Test proposal',
            'bid_amount' => 1000,
            'currency' => 'ETB',
            'estimated_duration' => '1 week',
            'status' => 'accepted',
        ]);
    }

    public function test_success_rate_is_null_when_no_ended_contracts(): void
    {
        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.success_rate', null);
    }

    public function test_success_rate_calculates_correctly_with_completed_and_cancelled_contracts(): void
    {
        $job1 = $this->createJob();
        $prop1 = $this->createProposal($job1);

        // Contract 1: Completed
        Contract::create([
            'job_id' => $job1->id,
            'proposal_id' => $prop1->id,
            'employer_id' => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title' => 'Job 1',
            'budget_type' => 'fixed',
            'agreed_rate' => 1000,
            'total_amount' => 1000,
            'status' => 'completed',
        ]);

        // Contract 2: Cancelled
        $job2 = $this->createJob();
        $prop2 = $this->createProposal($job2);

        Contract::create([
            'job_id' => $job2->id,
            'proposal_id' => $prop2->id,
            'employer_id' => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title' => 'Job 2',
            'budget_type' => 'fixed',
            'agreed_rate' => 1000,
            'total_amount' => 1000,
            'status' => 'cancelled',
        ]);

        // 1 completed out of 2 ended contracts = 50%
        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.success_rate', 50);
    }

    public function test_success_rate_is_100_percent_when_all_completed(): void
    {
        $job1 = $this->createJob();
        $prop1 = $this->createProposal($job1);

        Contract::create([
            'job_id' => $job1->id,
            'proposal_id' => $prop1->id,
            'employer_id' => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title' => 'Job 1',
            'budget_type' => 'fixed',
            'agreed_rate' => 2000,
            'total_amount' => 2000,
            'status' => 'completed',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.success_rate', 100);
    }
}
