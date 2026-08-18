<?php

namespace Tests\Feature\Api\V1;

use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    public function test_employer_can_view_own_contract(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $data['contract']->id)
            ->assertJsonPath('data.currency', 'ETB')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_freelancer_can_view_own_contract(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['freelancer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $data['contract']->id);
    }

    public function test_employer_cannot_view_another_employers_contract(): void
    {
        $data = $this->createContract();
        $otherEmployer = $this->createContractUser('employer');

        $response = $this->actingAsSanctum($otherEmployer)
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(403);
    }

    public function test_freelancer_cannot_view_another_freelancers_contract(): void
    {
        $data = $this->createContract();
        $otherFreelancer = $this->createContractUser('freelancer');

        $response = $this->actingAsSanctum($otherFreelancer)
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/contracts')->assertStatus(401);
        $this->getJson('/api/v1/contracts/1')->assertStatus(401);
    }

    public function test_contract_list_returns_only_own_contracts(): void
    {
        $data = $this->createContract();
        $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->getJson('/api/v1/contracts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $contracts = $response->json('data');
        $this->assertCount(1, $contracts);
        $this->assertEquals($data['contract']->id, $contracts[0]['id']);
    }

    public function test_admin_can_view_any_contract(): void
    {
        $data = $this->createContract();
        $admin = $this->createContractUser('admin');

        $this->actingAsSanctum($admin)
            ->getJson('/api/v1/contracts/' . $data['contract']->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $data['contract']->id);
    }

    public function test_nonexistent_contract_returns_404(): void
    {
        $employer = $this->createContractUser('employer');

        $this->actingAsSanctum($employer)
            ->getJson('/api/v1/contracts/999999')
            ->assertStatus(404);
    }

    public function test_employer_can_pause_contract(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/pause');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'paused');

        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'paused',
        ]);
    }

    public function test_freelancer_cannot_pause_contract(): void
    {
        $data = $this->createContract();

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/pause')
            ->assertStatus(403);
    }

    public function test_paused_contract_can_be_resumed(): void
    {
        $data = $this->createContract(['status' => 'paused']);

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/resume');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_contract_transition_rejected(): void
    {
        $data = $this->createContract();

        // Resuming an active contract is invalid.
        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/resume')
            ->assertStatus(422);

        // Pausing a completed contract is invalid.
        $completed = $this->createContract(['status' => 'completed']);
        $this->actingAsSanctum($completed['employer'])
            ->postJson('/api/v1/contracts/' . $completed['contract']->id . '/pause')
            ->assertStatus(422);
    }

    public function test_contract_cannot_complete_with_unfinished_milestones(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract']); // pending

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/complete');

        $response->assertStatus(422);

        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'active',
        ]);
    }

    public function test_contract_completes_when_all_milestones_are_approved(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract'], ['status' => 'approved', 'approved_at' => now()]);
        $this->createMilestone($data['contract'], ['status' => 'approved', 'approved_at' => now()]);

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/complete');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.summary.completed_milestones_count', 2);

        $this->assertNotNull($response->json('data.end_date'));
        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'completed',
        ]);
    }

    public function test_double_completion_prevented(): void
    {
        $data = $this->createContract([
            'status' => 'completed',
            'end_date' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/complete')
            ->assertStatus(422);
    }

    public function test_employer_can_cancel_contract(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/cancel');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancelled_contract_cannot_be_modified(): void
    {
        $data = $this->createContract(['status' => 'cancelled']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/pause')
            ->assertStatus(422);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/resume')
            ->assertStatus(422);
    }

    public function test_completed_contract_cannot_be_modified(): void
    {
        $data = $this->createContract(['status' => 'completed']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/cancel')
            ->assertStatus(422);
    }

    public function test_freelancer_cannot_complete_contract(): void
    {
        $data = $this->createContract();

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/complete')
            ->assertStatus(403);
    }

    public function test_failed_completion_leaves_state_unchanged(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/complete')
            ->assertStatus(422);

        $contract = Contract::find($data['contract']->id);
        $this->assertEquals('active', $contract->status);
        $this->assertNull($contract->end_date);
    }

    public function test_contract_response_omits_sensitive_data(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(200);
        $payload = $response->json('data');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('password', $payload['employer']);
        $this->assertArrayNotHasKey('password', $payload['freelancer']);
    }

    public function test_contract_summary_totals_are_computed_on_backend(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract'], [
            'title' => 'Design',
            'amount' => 10000,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $this->createMilestone($data['contract'], [
            'title' => 'Build',
            'amount' => 20000,
            'status' => 'pending',
        ]);

        $response = $this->actingAsSanctum($data['freelancer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id);

        $response->assertStatus(200);
        $summary = $response->json('data.summary');

        $this->assertEquals(30000, $summary['total_milestone_amount']);
        $this->assertEquals(10000, $summary['completed_milestone_amount']);
        $this->assertEquals(20000, $summary['remaining_milestone_amount']);
        $this->assertEquals(2, $summary['milestones_count']);
        $this->assertEquals(1, $summary['completed_milestones_count']);
        $this->assertEquals(1, $summary['pending_milestones_count']);
        $this->assertEquals(50, $summary['progress_percent']);
    }
}
