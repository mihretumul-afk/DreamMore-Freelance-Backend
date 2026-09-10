<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
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
    private Milestone $milestone;

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

        // Assign super_admin role and required permissions for dispute resolution
        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $resolvePermission = Permission::firstOrCreate(['slug' => 'disputes.resolve'], ['name' => 'Resolve Disputes', 'group' => 'disputes']);
        $viewPermission    = Permission::firstOrCreate(['slug' => 'disputes.view'], ['name' => 'View Disputes', 'group' => 'disputes']);
        $financePermission = Permission::firstOrCreate(['slug' => 'finance.view'], ['name' => 'View Finance', 'group' => 'system']);
        $superAdminRole->permissions()->syncWithoutDetaching([$resolvePermission->id, $viewPermission->id, $financePermission->id]);
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

        $this->milestone = Milestone::create([
            'contract_id' => $this->contract->id,
            'created_by'  => $this->employer->id,
            'title'       => 'Frontend Design',
            'amount'      => 50000,
            'status'      => 'submitted',
        ]);

        Wallet::forUser($this->employer->id);
        Wallet::forUser($this->freelancer->id);
    }

    public function test_employer_can_raise_dispute_on_milestone(): void
    {
        $response = $this->actingAs($this->employer)
            ->postJson("/api/v1/contracts/{$this->contract->id}/milestones/{$this->milestone->id}/dispute", [
                'reason' => 'Deliverables not meeting specifications',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'disputed');

        $this->assertEquals('disputed', $this->contract->fresh()->status);

        $this->assertDatabaseHas('reports', [
            'target_type' => 'milestone',
            'target_id'   => $this->milestone->id,
            'reporter_id' => $this->employer->id,
            'reason'      => 'Deliverables not meeting specifications',
        ]);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type'    => 'dispute_raised',
        ]);
    }

    public function test_admin_can_resolve_contract_dispute(): void
    {
        $this->contract->update(['status' => 'disputed']);

        // Resolution releases funds to the freelancer, so the milestone must
        // have a completed escrow payment — money movement is now enforced
        // (a failed release rolls the whole resolution back).
        Payment::create([
            'reference'          => Payment::generateReference(),
            'payer_id'           => $this->employer->id,
            'milestone_id'       => $this->milestone->id,
            'type'               => Payment::TYPE_ESCROW_FUNDED,
            'amount'             => 50000,
            'platform_fee'       => 0,
            'processing_fee'     => 0,
            'net_amount'         => 50000,
            'currency'           => 'ETB',
            'status'             => Payment::STATUS_COMPLETED,
        ]);

        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'milestone',
            'target_id'   => $this->milestone->id,
            'reason'      => 'Scope disagreement',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'resolution'      => 'Mediation conducted. Milestone revised.',
                'resolution_type' => 'release_to_freelancer',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');

        // Funds actually released to the freelancer: the milestone was the
        // contract's only one, so the contract is completed (money movement
        // is now enforced — previously the release failed silently and the
        // contract stayed 'active' while claiming success).
        $this->assertEquals('completed', $this->contract->fresh()->status);

        // Both parties received resolution notifications
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type'    => 'dispute_resolved',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type'    => 'dispute_resolved',
        ]);
    }

    public function test_admin_can_resolve_dispute_with_refund_to_employer_updates_wallet_and_finance(): void
    {
        $this->contract->update(['status' => 'disputed']);
        $this->milestone->update(['status' => 'disputed']);

        $fundingPayment = Payment::create([
            'reference'          => Payment::generateReference(),
            'payer_id'           => $this->employer->id,
            'milestone_id'       => $this->milestone->id,
            'type'               => Payment::TYPE_ESCROW_FUNDED,
            'amount'             => 50000,
            'platform_fee'       => 1000,
            'processing_fee'     => 0,
            'net_amount'         => 49000,
            'currency'           => 'ETB',
            'status'             => Payment::STATUS_COMPLETED,
        ]);

        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'milestone',
            'target_id'   => $this->milestone->id,
            'reason'      => 'Incomplete work',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'resolution'      => 'Refunding employer.',
                'resolution_type' => 'refund_to_employer',
            ]);

        $response->assertStatus(200);

        // Employer wallet credited
        $employerWallet = Wallet::where('user_id', $this->employer->id)->first();
        $this->assertEquals(50000, (float) $employerWallet->fresh()->available_balance);

        // Refund payment created
        $this->assertDatabaseHas('payments', [
            'type'      => Payment::TYPE_REFUND,
            'status'    => Payment::STATUS_COMPLETED,
            'amount'    => 50000,
            'payee_id'  => $this->employer->id,
        ]);

        // Milestone cancelled
        $this->assertEquals('cancelled', $this->milestone->fresh()->status);

        // Check Finance dashboard total_refunds
        $financeRes = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance/dashboard');
        $financeRes->assertStatus(200)
            ->assertJsonPath('data.total_refunds', 50000);
    }

    public function test_freelancer_can_view_their_disputes_and_detail(): void
    {
        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'contract',
            'target_id'   => $this->contract->id,
            'reason'      => 'Incomplete deliverables',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->getJson('/api/v1/disputes');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $report->id);

        $showResponse = $this->actingAs($this->freelancer)
            ->getJson("/api/v1/disputes/{$report->id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_admin_can_send_targeted_note_to_freelancer_only(): void
    {
        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'contract',
            'target_id'   => $this->contract->id,
            'reason'      => 'Quality issue',
            'status'      => 'pending',
        ]);

        // Admin sends note to freelancer only
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/reports/{$report->id}/notes", [
                'note'           => 'Please re-upload your work files.',
                'recipient_type' => 'freelancer',
            ]);

        $response->assertStatus(200);

        // Freelancer can view note
        $freelancerRes = $this->actingAs($this->freelancer)
            ->getJson("/api/v1/disputes/{$report->id}");
        $freelancerRes->assertStatus(200);
        $this->assertCount(1, $freelancerRes->json('data.notes_list'));

        // Employer cannot view freelancer-only note
        $employerRes = $this->actingAs($this->employer)
            ->getJson("/api/v1/disputes/{$report->id}");
        $employerRes->assertStatus(200);
        $this->assertCount(0, $employerRes->json('data.notes_list'));

        // Freelancer received notification, Employer did not
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type'    => 'dispute_update',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->employer->id,
            'type'    => 'dispute_update',
        ]);
    }

    public function test_admin_can_send_targeted_note_to_employer_only(): void
    {
        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'contract',
            'target_id'   => $this->contract->id,
            'reason'      => 'Quality issue',
            'status'      => 'pending',
        ]);

        // Admin sends note to employer only
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/reports/{$report->id}/notes", [
                'note'           => 'Please clarify the milestone specifications.',
                'recipient_type' => 'employer',
            ]);

        $response->assertStatus(200);

        // Employer can view note
        $employerRes = $this->actingAs($this->employer)
            ->getJson("/api/v1/disputes/{$report->id}");
        $employerRes->assertStatus(200);
        $this->assertCount(1, $employerRes->json('data.notes_list'));

        // Freelancer cannot view employer-only note
        $freelancerRes = $this->actingAs($this->freelancer)
            ->getJson("/api/v1/disputes/{$report->id}");
        $freelancerRes->assertStatus(200);
        $this->assertCount(0, $freelancerRes->json('data.notes_list'));

        // Employer received notification, Freelancer did not
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type'    => 'dispute_update',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->freelancer->id,
            'type'    => 'dispute_update',
        ]);
    }

    public function test_freelancer_can_reply_to_dispute(): void
    {
        $report = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'contract',
            'target_id'   => $this->contract->id,
            'reason'      => 'Quality issue',
            'status'      => 'pending',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->postJson("/api/v1/disputes/{$report->id}/notes", [
                'note' => 'I have uploaded the requested revisions.',
            ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.notes_list'));

        // Employer cannot view freelancer's note
        $employerRes = $this->actingAs($this->employer)
            ->getJson("/api/v1/disputes/{$report->id}");
        $employerRes->assertStatus(200);
        $this->assertCount(0, $employerRes->json('data.notes_list'));

        // Employer received no notification
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->employer->id,
            'type'    => 'dispute_update',
        ]);
    }
}
