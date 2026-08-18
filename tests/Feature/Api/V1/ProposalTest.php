<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    /**
     * Create a standalone job owned by the given employer.
     */
    private function createJob(User $employer, array $overrides = []): Job
    {
        return Job::create(array_merge([
            'employer_id' => $employer->id,
            'title' => 'Build a Laravel E-Commerce Site',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need an experienced Laravel developer in Addis Ababa.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'location' => 'Addis Ababa',
        ], $overrides));
    }

    /**
     * Create a proposal for the given job and freelancer.
     */
    private function createProposal(Job $job, User $freelancer, array $overrides = []): Proposal
    {
        return Proposal::create(array_merge([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I can deliver this project with high quality.',
            'bid_amount' => 45000,
            'estimated_duration' => '4 weeks',
        ], $overrides));
    }

    // ---------------------------------------------------------------- Creation

    public function test_freelancer_can_submit_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                'cover_letter' => 'I can deliver this project with high quality.',
                'bid_amount' => 45000,
                'estimated_duration' => '4 weeks',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', $job->id)
            ->assertJsonPath('data.freelancer_id', $freelancer->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.currency', 'ETB')
            ->assertJsonPath('data.bid_amount', 45000);

        $this->assertDatabaseHas('proposals', [
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $job->id,
            'proposals_count' => 1,
        ]);
    }

    public function test_unauthenticated_user_cannot_submit_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
            'cover_letter' => 'I can deliver this project.',
            'bid_amount' => 45000,
            'estimated_duration' => '4 weeks',
        ])->assertStatus(401);
    }

    public function test_employer_cannot_submit_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                'cover_letter' => 'I can deliver this project.',
                'bid_amount' => 45000,
                'estimated_duration' => '4 weeks',
            ])
            ->assertStatus(403);
    }

    public function test_invalid_job_rejected(): void
    {
        $freelancer = $this->createContractUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/999999/proposals', [
                'cover_letter' => 'I can deliver this project.',
                'bid_amount' => 45000,
                'estimated_duration' => '4 weeks',
            ])
            ->assertStatus(404);
    }

    public function test_closed_job_cannot_receive_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $closed = $this->createJob($employer, ['status' => 'closed']);
        $inProgress = $this->createJob($employer, ['status' => 'in_progress']);

        foreach ([$closed, $inProgress] as $job) {
            $this->actingAsSanctum($freelancer)
                ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                    'cover_letter' => 'I can deliver this project.',
                    'bid_amount' => 45000,
                    'estimated_duration' => '4 weeks',
                ])
                ->assertStatus(422);
        }
    }

    public function test_proposal_validation_errors_are_returned(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cover_letter', 'bid_amount', 'estimated_duration']);
    }

    public function test_duplicate_active_proposal_is_prevented(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                'cover_letter' => 'Second attempt.',
                'bid_amount' => 50000,
                'estimated_duration' => '3 weeks',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_withdrawn_proposal_allows_resubmission(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/proposals/' . $proposal->id . '/withdraw')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'withdrawn');

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                'cover_letter' => 'Fresh attempt after withdrawing.',
                'bid_amount' => 47000,
                'estimated_duration' => '3 weeks',
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('proposals', 2);
    }

    // ----------------------------------------------------------------- Reading

    public function test_freelancer_can_view_own_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/proposals/' . $proposal->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $proposal->id)
            ->assertJsonPath('data.job.title', $job->title);
    }

    public function test_employer_can_view_proposals_for_own_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancerA = $this->createContractUser('freelancer');
        $freelancerB = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $this->createProposal($job, $freelancerA);
        $this->createProposal($job, $freelancerB);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/jobs/' . $job->id . '/proposals');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_employer_cannot_view_proposals_for_another_employers_job(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($otherEmployer)
            ->getJson('/api/v1/jobs/' . $job->id . '/proposals')
            ->assertStatus(403);
    }

    public function test_freelancer_cannot_view_another_freelancers_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancerA = $this->createContractUser('freelancer');
        $freelancerB = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancerA);

        $this->actingAsSanctum($freelancerB)
            ->getJson('/api/v1/proposals/' . $proposal->id)
            ->assertStatus(403);
    }

    public function test_invalid_proposal_returns_404(): void
    {
        $freelancer = $this->createContractUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/proposals/999999')
            ->assertStatus(404);
    }

    public function test_employer_can_view_single_proposal_for_own_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->getJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $proposal->id)
            ->assertJsonPath('data.freelancer.name', $freelancer->name);
    }

    public function test_freelancer_can_list_own_proposals(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $jobA = $this->createJob($employer, ['title' => 'Job A']);
        $jobB = $this->createJob($employer, ['title' => 'Job B']);
        $this->createProposal($jobA, $freelancer);
        $this->createProposal($jobB, $freelancer);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/proposals');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    // ---------------------------------------------------------------- Updating

    public function test_freelancer_can_update_own_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $response = $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Updated cover letter.',
                'bid_amount' => 50000,
                'estimated_duration' => '3 weeks',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.bid_amount', 50000)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'bid_amount' => 50000,
        ]);
    }

    public function test_freelancer_cannot_update_another_freelancers_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancerA = $this->createContractUser('freelancer');
        $freelancerB = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancerA);

        $this->actingAsSanctum($freelancerB)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Hijacked.',
                'bid_amount' => 1000,
                'estimated_duration' => '1 week',
            ])
            ->assertStatus(403);
    }

    public function test_accepted_proposal_cannot_be_edited(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['status' => 'accepted']);

        $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Too late.',
                'bid_amount' => 1000,
                'estimated_duration' => '1 week',
            ])
            ->assertStatus(422);
    }

    public function test_rejected_proposal_cannot_be_edited(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['status' => 'rejected']);

        $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Too late.',
                'bid_amount' => 1000,
                'estimated_duration' => '1 week',
            ])
            ->assertStatus(422);
    }

    public function test_withdrawn_proposal_cannot_be_edited(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['status' => 'withdrawn']);

        $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Too late.',
                'bid_amount' => 1000,
                'estimated_duration' => '1 week',
            ])
            ->assertStatus(422);
    }

    public function test_freelancer_cannot_change_proposal_status_directly(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/proposals/' . $proposal->id, [
                'cover_letter' => 'Still pending.',
                'bid_amount' => 46000,
                'estimated_duration' => '3 weeks',
                'status' => 'accepted',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'status' => 'pending',
        ]);
    }

    // -------------------------------------------------------------- Withdrawal

    public function test_freelancer_can_withdraw_own_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/proposals/' . $proposal->id . '/withdraw')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'status' => 'withdrawn',
        ]);
    }

    public function test_freelancer_cannot_withdraw_another_freelancers_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancerA = $this->createContractUser('freelancer');
        $freelancerB = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancerA);

        $this->actingAsSanctum($freelancerB)
            ->postJson('/api/v1/proposals/' . $proposal->id . '/withdraw')
            ->assertStatus(403);
    }

    public function test_accepted_proposal_cannot_be_withdrawn(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['status' => 'accepted']);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/proposals/' . $proposal->id . '/withdraw')
            ->assertStatus(422);
    }

    // ----------------------------------------------------- Employer management

    public function test_employer_can_shortlist_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/shortlist')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'shortlisted');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'status' => 'shortlisted',
        ]);
    }

    public function test_employer_can_reject_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/reject')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'status' => 'rejected',
        ]);
    }

    public function test_employer_can_accept_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $response = $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted');

        $this->assertNotNull($response->json('data.contract.id'));
        $this->assertDatabaseHas('contracts', [
            'proposal_id' => $proposal->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'status' => 'accepted',
        ]);
    }

    public function test_employer_cannot_manage_another_employers_proposal(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($otherEmployer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(403);

        $this->actingAsSanctum($otherEmployer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/reject')
            ->assertStatus(403);
    }

    public function test_shortlisted_proposal_cannot_be_shortlisted_again(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['status' => 'shortlisted']);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/shortlist')
            ->assertStatus(422);
    }

    // ---------------------------------------------------- Contract integration

    public function test_accept_creates_exactly_one_contract(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer, ['bid_amount' => 48000]);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(200);

        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseHas('contracts', [
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'title' => $job->title,
            'budget_type' => $job->budget_type,
            'agreed_rate' => 48000,
            'total_amount' => 48000,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_acceptance_does_not_create_duplicate_contract(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(200);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(422);

        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_accept_rejects_other_active_proposals(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancerA = $this->createContractUser('freelancer');
        $freelancerB = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $winning = $this->createProposal($job, $freelancerA);
        $other = $this->createProposal($job, $freelancerB);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $winning->id . '/accept')
            ->assertStatus(200);

        $this->assertDatabaseHas('proposals', [
            'id' => $winning->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('proposals', [
            'id' => $other->id,
            'status' => 'rejected',
        ]);
    }

    public function test_accept_marks_job_as_in_progress(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(200);

        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $job->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_job_in_progress_cannot_receive_new_proposals(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id . '/accept')
            ->assertStatus(200);

        $otherFreelancer = $this->createContractUser('freelancer');
        $this->actingAsSanctum($otherFreelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/proposals', [
                'cover_letter' => 'Too late to apply.',
                'bid_amount' => 10000,
                'estimated_duration' => '1 week',
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- Security

    public function test_proposal_response_omits_sensitive_data(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);
        $proposal = $this->createProposal($job, $freelancer);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/jobs/' . $job->id . '/proposals/' . $proposal->id);

        $response->assertStatus(200);
        $payload = $response->json('data');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('email', $payload['freelancer']);
        $this->assertArrayNotHasKey('password', $payload['freelancer']);
    }
}
