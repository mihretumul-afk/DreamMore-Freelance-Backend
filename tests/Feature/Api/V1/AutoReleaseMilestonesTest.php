<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use App\Models\Contract;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoReleaseMilestonesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employer;
    private User $freelancer;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->employer = User::create([
            'name'     => 'Employer User',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);

        $this->freelancer = User::create([
            'name'     => 'Freelancer User',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);

        Wallet::forUser($this->employer->id);
        Wallet::forUser($this->freelancer->id);
    }

    public function test_admin_can_update_auto_release_days_setting(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson('/api/v1/admin/settings', [
                'auto_release_days' => '10',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $response->json('data.payments.auto_release_days'));
        $this->assertDatabaseHas('admin_settings', [
            'key'   => 'auto_release_days',
            'value' => '10',
        ]);
    }

    public function test_auto_release_command_approves_and_releases_expired_milestone(): void
    {
        AdminSetting::setValue('auto_release_days', '14', 'integer');

        $job = Job::create([
            'employer_id' => $this->employer->id,
            'title'       => 'Test Job',
            'slug'        => 'test-job-' . Str::random(5),
            'description' => 'Description',
            'budget_type' => 'fixed',
            'budget_min'  => 1000,
            'status'      => 'open',
        ]);

        $proposal = \App\Models\Proposal::create([
            'job_id'        => $job->id,
            'freelancer_id' => $this->freelancer->id,
            'cover_letter'  => 'Test cover letter',
            'estimated_duration' => '1_month',
            'bid_amount'    => 1000.00,
            'status'        => 'accepted',
        ]);

        $contract = Contract::create([
            'job_id'        => $job->id,
            'proposal_id'   => $proposal->id,
            'employer_id'   => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title'         => 'Test Contract',
            'agreed_rate'   => 1000.00,
            'total_amount'  => 1000.00,
            'status'        => Contract::STATUS_ACTIVE,
        ]);

        $milestone = Milestone::create([
            'contract_id'  => $contract->id,
            'title'        => 'Milestone 1',
            'amount'       => 1000.00,
            'status'       => Milestone::STATUS_SUBMITTED,
            'created_by'   => $this->employer->id,
            'submitted_at' => now()->subDays(15), // Exceeds 14 days
        ]);

        // Escrow funded payment
        Payment::create([
            'user_id'        => $this->employer->id,
            'payer_id'       => $this->employer->id,
            'milestone_id'   => $milestone->id,
            'contract_id'    => $contract->id,
            'amount'         => 1000.00,
            'net_amount'     => 920.00,
            'type'           => Payment::TYPE_ESCROW_FUNDED,
            'status'         => Payment::STATUS_COMPLETED,
            'payment_method' => 'chapa',
            'reference'      => 'PAY-123456',
        ]);

        $this->artisan('milestones:auto-release')
            ->assertExitCode(0);

        $milestone->refresh();
        $this->assertEquals(Milestone::STATUS_RELEASED, $milestone->status);
        $this->assertNotNull($milestone->approved_at);

        // Verify freelancer received net balance in available balance
        $freelancerWallet = Wallet::forUser($this->freelancer->id);
        $this->assertGreaterThan(0, (float) $freelancerWallet->available_balance);
    }

    public function test_auto_release_command_skips_recent_and_disputed_milestones(): void
    {
        AdminSetting::setValue('auto_release_days', '14', 'integer');

        $job = Job::create([
            'employer_id' => $this->employer->id,
            'title'       => 'Test Job 2',
            'slug'        => 'test-job-2-' . Str::random(5),
            'description' => 'Description',
            'budget_type' => 'fixed',
            'budget_min'  => 500,
            'status'      => 'open',
        ]);

        $proposal1 = \App\Models\Proposal::create([
            'job_id'             => $job->id,
            'freelancer_id'      => $this->freelancer->id,
            'cover_letter'       => 'Test cover letter',
            'estimated_duration' => '1_month',
            'bid_amount'         => 500.00,
            'status'             => 'accepted',
        ]);

        $proposal2 = \App\Models\Proposal::create([
            'job_id'             => $job->id,
            'freelancer_id'      => $this->freelancer->id,
            'cover_letter'       => 'Test cover letter 2',
            'estimated_duration' => '1_month',
            'bid_amount'         => 500.00,
            'status'             => 'accepted',
        ]);

        $activeContract = Contract::create([
            'job_id'        => $job->id,
            'proposal_id'   => $proposal1->id,
            'employer_id'   => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title'         => 'Active Contract',
            'agreed_rate'   => 500.00,
            'total_amount'  => 500.00,
            'status'        => Contract::STATUS_ACTIVE,
        ]);

        $disputedContract = Contract::create([
            'job_id'        => $job->id,
            'proposal_id'   => $proposal2->id,
            'employer_id'   => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title'         => 'Disputed Contract',
            'agreed_rate'   => 500.00,
            'total_amount'  => 500.00,
            'status'        => Contract::STATUS_DISPUTED,
        ]);

        // Recent submission (only 5 days old)
        $recentMilestone = Milestone::create([
            'contract_id'  => $activeContract->id,
            'title'        => 'Recent Milestone',
            'amount'       => 500.00,
            'status'       => Milestone::STATUS_SUBMITTED,
            'created_by'   => $this->employer->id,
            'submitted_at' => now()->subDays(5),
        ]);

        // Disputed contract milestone (15 days old)
        $disputedMilestone = Milestone::create([
            'contract_id'  => $disputedContract->id,
            'title'        => 'Disputed Milestone',
            'amount'       => 500.00,
            'status'       => Milestone::STATUS_SUBMITTED,
            'created_by'   => $this->employer->id,
            'submitted_at' => now()->subDays(15),
        ]);

        $this->artisan('milestones:auto-release')
            ->assertExitCode(0);

        $this->assertEquals(Milestone::STATUS_SUBMITTED, $recentMilestone->fresh()->status);
        $this->assertEquals(Milestone::STATUS_SUBMITTED, $disputedMilestone->fresh()->status);
    }
}
