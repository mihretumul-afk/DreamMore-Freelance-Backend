<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $employer;
    private User $freelancer;
    private User $admin;
    private Contract $contract;

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

        $job = Job::create([
            'employer_id' => $this->employer->id,
            'title' => 'E-Commerce Website',
            'slug' => 'e-commerce-' . Str::random(8),
            'description' => 'Build a store',
            'budget_type' => 'fixed',
            'min_budget' => 50000,
            'max_budget' => 50000,
            'status' => 'in_progress',
        ]);

        $proposal = Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $this->freelancer->id,
            'cover_letter' => 'Proposal',
            'bid_amount' => 50000,
            'currency' => 'ETB',
            'estimated_duration' => '1 month',
            'status' => 'accepted',
        ]);

        $this->contract = Contract::create([
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $this->employer->id,
            'freelancer_id' => $this->freelancer->id,
            'title' => 'E-Commerce Website Contract',
            'budget_type' => 'fixed',
            'agreed_rate' => 50000,
            'total_amount' => 50000,
            'status' => 'active',
        ]);
    }

    public function test_employer_can_raise_dispute_on_contract(): void
    {
        $response = $this->actingAs($this->employer)
            ->postJson("/api/v1/contracts/{$this->contract->id}/dispute", [
                'reason' => 'Deliverables not meeting specifications',
                'description' => 'Freelancer missed 2 milestone deliverables.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'disputed');

        $this->assertEquals('disputed', $this->contract->fresh()->status);

        $this->assertDatabaseHas('reports', [
            'target_type' => 'contract',
            'target_id' => $this->contract->id,
            'reporter_id' => $this->employer->id,
            'reason' => 'Deliverables not meeting specifications',
        ]);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'dispute_raised',
        ]);

        // Admin notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'admin_contract_disputed',
        ]);
    }

    public function test_admin_can_resolve_contract_dispute(): void
    {
        $this->contract->update(['status' => 'disputed']);

        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'contract',
            'target_id' => $this->contract->id,
            'reason' => 'Scope disagreement',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'resolution' => 'Mediation conducted. Milestone revised.',
                'contract_action' => 'active',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');

        $this->assertEquals('active', $this->contract->fresh()->status);

        // Both parties received resolution notifications
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'dispute_resolved',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type' => 'dispute_resolved',
        ]);
    }
}
