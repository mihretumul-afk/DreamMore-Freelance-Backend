<?php

namespace Tests\Feature\Api\V1;

use App\Models\Milestone;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\FreelancerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

/**
 * Comprehensive end-to-end test for the complete milestone lifecycle:
 *
 *   Employer creates milestone → awaiting_funding
 *   Freelancer cannot start (not funded)
 *   Employer funds → funded
 *   Freelancer starts → in_progress
 *   Freelancer submits → submitted
 *   Employer requests revision → revision_requested
 *   Freelancer resubmits → submitted
 *   Employer approves → approved
 *   Employer releases payment → paid
 *   Freelancer earnings updated
 *   Balance summary correct
 */
class MilestoneWorkflowE2ETest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    public function test_complete_milestone_lifecycle_from_creation_to_payment_release(): void
    {
        // ════════════════════════════════════════════════════════════════
        // SETUP: Create contract with employer and freelancer
        // ════════════════════════════════════════════════════════════════
        $data = $this->createContract();
        $employer = $data['employer'];
        $freelancer = $data['freelancer'];
        $contract = $data['contract'];

        // Create freelancer profile for earnings tracking
        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 1: Employer creates milestone → awaiting_funding
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones", [
                'title' => 'Homepage Design',
                'description' => 'Complete responsive homepage design with hero section.',
                'amount' => 25000,
                'due_date' => now()->addDays(14)->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'awaiting_funding')
            ->assertJsonPath('data.title', 'Homepage Design')
            ->assertJsonPath('data.amount', 25000);

        $milestone = Milestone::where('contract_id', $contract->id)->first();
        $this->assertEquals('awaiting_funding', $milestone->status);
        $this->assertNull($milestone->escrow_funded_at);
        $this->assertNull($milestone->started_at);

        // Freelancer receives milestone_created notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_created',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 2: Freelancer CANNOT start work (not funded)
        // ════════════════════════════════════════════════════════════════
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/start")
            ->assertStatus(422);

        $this->assertEquals('awaiting_funding', $milestone->fresh()->status);

        // ════════════════════════════════════════════════════════════════
        // STEP 3: Freelancer CANNOT submit work (not funded)
        // ════════════════════════════════════════════════════════════════
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submit")
            ->assertStatus(422);

        // ════════════════════════════════════════════════════════════════
        // STEP 4: Employer funds milestone → funded
        // ════════════════════════════════════════════════════════════════
        // Simulate funding by updating milestone directly (bypasses payment gateway)
        $milestone->update([
            'status' => 'funded',
            'escrow_funded_at' => now(),
        ]);

        // Note: milestone_funded notification is sent by PaymentController::fundMilestone()
        // when using the real payment flow. Here we simulate funding directly.

        $this->assertEquals('funded', $milestone->fresh()->status);
        $this->assertNotNull($milestone->fresh()->escrow_funded_at);

        // ════════════════════════════════════════════════════════════════
        // STEP 5: Freelancer starts work → in_progress
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'in_progress',
        ]);
        $this->assertNotNull($milestone->fresh()->started_at);

        // Employer receives milestone_started notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'milestone_started',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 6: Freelancer submits work → submitted
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Completed the homepage design with all responsive breakpoints.',
                'links' => ['https://figma.com/design/homepage-v1'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submissions_count', 1);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'submitted',
        ]);

        // Employer receives milestone_submitted notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'milestone_submitted',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 7: Employer requests revision → revision_requested
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/revision", [
                'revision_note' => 'Please add mobile navigation menu and fix the footer alignment.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'revision_requested');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'revision_requested',
        ]);

        // Freelancer receives milestone_revision notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_revision',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 8: Freelancer resubmits → submitted
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Updated with mobile navigation and fixed footer alignment.',
                'links' => ['https://figma.com/design/homepage-v2'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submissions_count', 2);

        // ════════════════════════════════════════════════════════════════
        // STEP 9: Employer approves → approved
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'approved',
        ]);
        $this->assertNotNull($milestone->fresh()->approved_at);

        // Freelancer receives milestone_approved notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_approved',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 10: Employer releases payment → paid
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/release");

        $response->assertStatus(200);

        // The response is the payment record; verify milestone is paid in DB
        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'paid',
        ]);
        $this->assertNotNull($milestone->fresh()->paid_at);

        // Payment record created
        $this->assertDatabaseHas('payments', [
            'milestone_id' => $milestone->id,
            'type' => Payment::TYPE_MILESTONE_RELEASED,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // Freelancer receives milestone_paid notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_paid',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 11: Verify transaction records
        // ════════════════════════════════════════════════════════════════
        // Freelancer has a credit transaction
        $this->assertDatabaseHas('transactions', [
            'user_id' => $freelancer->id,
            'direction' => 'credit',
            'type' => Payment::TYPE_MILESTONE_RELEASED,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 12: Verify freelancer earnings updated
        // ════════════════════════════════════════════════════════════════
        $freelancerProfile = FreelancerProfile::where('user_id', $freelancer->id)->first();
        $this->assertGreaterThan(0, (float) $freelancerProfile->total_earnings);

        // ════════════════════════════════════════════════════════════════
        // STEP 13: Verify balance summary
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/balance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $balance = $response->json('data');
        $this->assertGreaterThan(0, $balance['total_earned']);
        $this->assertEquals('ETB', $balance['currency']);

        // ════════════════════════════════════════════════════════════════
        // STEP 14: Verify milestone earnings endpoint
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/earnings/milestones');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $earnings = $response->json('data');
        $this->assertNotEmpty($earnings);

        $milestoneEarning = collect($earnings)->firstWhere('id', $milestone->id);
        $this->assertNotNull($milestoneEarning);
        $this->assertEquals('paid', $milestoneEarning['status']);
        $this->assertTrue($milestoneEarning['is_released']);
        $this->assertGreaterThan(0, $milestoneEarning['net_amount']);
        $this->assertGreaterThan(0, $milestoneEarning['total_fee']);

        // ════════════════════════════════════════════════════════════════
        // STEP 15: Freelancer CANNOT start work on paid milestone
        // ════════════════════════════════════════════════════════════════
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/start")
            ->assertStatus(422);

        // Freelancer CANNOT submit on paid milestone
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submit")
            ->assertStatus(422);

        // Employer CANNOT approve paid milestone
        $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/approve")
            ->assertStatus(422);

        // Employer CANNOT release paid milestone again
        $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);

        // ════════════════════════════════════════════════════════════════
        // STEP 16: Verify submission history preserved
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->getJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submissions");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data'); // 2 submissions (original + resubmit)
    }

    public function test_employer_cannot_release_unapproved_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'funded',
            'escrow_funded_at' => now(),
        ]);

        // Cannot release a funded but not approved milestone
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);
    }

    public function test_freelancer_cannot_release_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        // Only employer can release
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(403);
    }

    public function test_employer_cannot_release_disputed_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'disputed',
            'escrow_funded_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);
    }

    public function test_double_funding_prevented(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'funded',
            'escrow_funded_at' => now(),
        ]);

        // Cannot fund an already-funded milestone
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/fund")
            ->assertStatus(422);
    }

    public function test_double_release_prevented(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'paid',
            'escrow_funded_at' => now(),
            'paid_at' => now(),
        ]);

        // Cannot release an already-paid milestone
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);
    }
}
