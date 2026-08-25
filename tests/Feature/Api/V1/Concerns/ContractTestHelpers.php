<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Models\Contract;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Str;

trait ContractTestHelpers
{
    /**
     * Create a user with the given role.
     */
    protected function createContractUser(string $role = 'freelancer'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * Create a freelancer with an approved credential so they can apply for jobs.
     */
    protected function createVerifiedFreelancer(array $userOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'freelancer',
            'status' => 'active',
        ], $userOverrides));

        \App\Models\FreelancerProfile::create([
            'user_id' => $user->id,
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        \App\Models\Credential::create([
            'user_id' => $user->id,
            'title' => 'Certified Professional',
            'type' => 'professional_qualification',
            'file_path' => 'credentials/test_cert.pdf',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        return $user;
    }

    /**
     * Create an employer, freelancer, job, proposal and active contract.
     *
     * @return array{employer: User, freelancer: User, job: Job, proposal: Proposal, contract: Contract}
     */
    protected function createContract(array $contractOverrides = []): array
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');

        $job = Job::create([
            'employer_id' => $employer->id,
            'title' => 'Build a Laravel E-Commerce Site',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need an experienced Laravel developer in Addis Ababa.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'location' => 'Addis Ababa',
        ]);

        $proposal = Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I can deliver this project with high quality.',
            'bid_amount' => 45000,
            'estimated_duration' => '4 weeks',
        ]);

        $contract = Contract::create(array_merge([
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'title' => 'Build a Laravel E-Commerce Site',
            'agreed_rate' => 45000,
            'total_amount' => 45000,
        ], $contractOverrides));

        return compact('employer', 'freelancer', 'job', 'proposal', 'contract');
    }

    /**
     * Create a milestone on the given contract.
     */
    protected function createMilestone(Contract $contract, array $overrides = []): Milestone
    {
        return Milestone::create(array_merge([
            'contract_id' => $contract->id,
            'title' => 'Phase 1: Database Design',
            'description' => 'Design and implement the database schema.',
            'amount' => 15000,
            'due_date' => now()->addDays(14),
        ], $overrides));
    }

    /**
     * Authenticate the given user against the sanctum guard.
     */
    protected function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }
}
