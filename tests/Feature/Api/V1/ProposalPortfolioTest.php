<?php

namespace Tests\Feature\Api\V1;

use App\Models\Job;
use App\Models\Notification;
use App\Models\PortfolioItem;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProposalPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $employer;
    private User $freelancer;
    private User $admin;
    private Job $job;
    private PortfolioItem $portfolioItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $this->freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        \App\Models\FreelancerProfile::create([
            'user_id' => $this->freelancer->id,
            'approval_status' => 'approved',
        ]);

        \App\Models\Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Certified Professional',
            'type' => 'professional_qualification',
            'file_path' => 'credentials/test.pdf',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        // Assign super_admin role so the admin has full access in tests.
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);

        $this->job = Job::create([
            'employer_id' => $this->employer->id,
            'title' => 'Build React Site',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need a frontend developer',
            'budget_type' => 'fixed',
            'min_budget' => 1000,
            'max_budget' => 5000,
            'status' => 'open',
        ]);

        $this->portfolioItem = PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'React Dashboard',
            'description' => 'Analytics UI dashboard',
            'project_url' => 'https://github.com/example/react-dash',
        ]);
    }

    public function test_freelancer_can_submit_proposal_with_attached_portfolio(): void
    {
        $response = $this->actingAs($this->freelancer)
            ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                'cover_letter' => 'I have deep experience with React and Laravel projects.',
                'bid_amount' => 4500,
                'currency' => 'ETB',
                'estimated_duration' => '3 weeks',
                'portfolio_item_ids' => [$this->portfolioItem->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bid_amount', 4500)
            ->assertJsonCount(1, 'data.portfolio_items')
            ->assertJsonPath('data.portfolio_items.0.title', 'React Dashboard');

        $this->assertDatabaseHas('proposal_portfolio_items', [
            'portfolio_item_id' => $this->portfolioItem->id,
        ]);

        // Verify employer received notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type' => 'new_proposal',
        ]);

        // Verify admin received notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'admin_proposal_created',
        ]);
    }

    public function test_employer_sees_attached_portfolio_in_proposals(): void
    {
        $proposal = Proposal::create([
            'job_id' => $this->job->id,
            'freelancer_id' => $this->freelancer->id,
            'cover_letter' => 'My proposal pitch',
            'bid_amount' => 3000,
            'currency' => 'ETB',
            'estimated_duration' => '10 days',
            'status' => 'pending',
        ]);

        $proposal->portfolioItems()->attach($this->portfolioItem->id);

        $response = $this->actingAs($this->employer)
            ->getJson("/api/v1/jobs/{$this->job->id}/proposals");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.portfolio_items.0.title', 'React Dashboard');
    }
}
