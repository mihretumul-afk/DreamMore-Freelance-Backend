<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Models\Category;
use App\Models\Contract;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;

trait ContractTestHelpers
{
    /**
     * Create a user with the given role and active status.
     */
    protected function createContractUser(string $role = 'freelancer'): User
    {
        return User::factory()->create([
            'role'   => $role,
            'status' => 'active',
        ]);
    }

    /**
     * Create a verified freelancer with a profile.
     */
    protected function createVerifiedFreelancer(): User
    {
        $user = User::factory()->create([
            'role'               => 'freelancer',
            'status'             => 'active',
            'email_verified_at'  => now(),
        ]);

        FreelancerProfile::create([
            'user_id'            => $user->id,
            'headline'           => 'Test Freelancer',
            'overview'           => 'Test overview.',
            'hourly_rate'        => 500.00,
            'experience_level'   => 'intermediate',
            'location'           => 'Addis Ababa',
            'availability_status'=> 'available',
        ]);

        \App\Models\Credential::create([
            'user_id'            => $user->id,
            'title'              => 'Verified Certificate',
            'type'               => 'external_certificate',
            'file_path'          => 'credentials/verified.pdf',
            'status'             => 'approved',
        ]);

        return $user;
    }

    /**
     * Create a full contract setup: employer, freelancer, job, proposal, contract.
     *
     * @return array{employer: User, freelancer: User, job: Job, proposal: Proposal, contract: Contract}
     */
    protected function createContract(array $jobOverrides = [], array $contractOverrides = []): array
    {
        $employer   = $this->createContractUser('employer');
        $freelancer = $this->createVerifiedFreelancer();

        $job = Job::create(array_merge([
            'employer_id' => $employer->id,
            'title'       => 'E-Commerce Website',
            'slug'        => 'e-commerce-' . Str::random(8),
            'description' => 'Build a store',
            'budget_type' => 'fixed',
            'min_budget'  => 50000,
            'max_budget'  => 50000,
            'location'    => 'Addis Ababa',
        ], $jobOverrides));

        $proposal = Proposal::create([
            'job_id'             => $job->id,
            'freelancer_id'      => $freelancer->id,
            'cover_letter'       => 'Proposal',
            'bid_amount'         => 50000,
            'estimated_duration' => '1 month',
            'status'             => 'accepted',
        ]);

        $contract = Contract::create(array_merge([
            'job_id'          => $job->id,
            'proposal_id'     => $proposal->id,
            'employer_id'     => $employer->id,
            'freelancer_id'   => $freelancer->id,
            'title'           => 'E-Commerce Website Contract',
            'budget_type'     => 'fixed',
            'agreed_rate'     => 50000,
            'total_amount'    => 50000,
            'status'          => 'active',
        ], $contractOverrides));

        return compact('employer', 'freelancer', 'job', 'proposal', 'contract');
    }

    /**
     * Create a milestone for the given contract.
     */
    protected function createMilestone(Contract $contract, array $overrides = []): Milestone
    {
        return Milestone::create(array_merge([
            'contract_id' => $contract->id,
            'created_by'  => $contract->employer_id,
            'title'       => 'Phase 1: UI Design',
            'amount'      => 15000,
        ], $overrides));
    }

    /**
     * Authenticate the request as the given user via the sanctum guard.
     */
    protected function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }
}
