<?php

namespace Tests\Feature\Api\V1;

use App\Models\Milestone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class MilestoneTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    public function test_employer_can_create_milestone(): void
    {
        $data = $this->createContract();

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones', [
                'title' => 'Phase 1: UI Design',
                'description' => 'Design the marketplace user interface.',
                'amount' => 15000,
                'due_date' => now()->addDays(10)->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Phase 1: UI Design')
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonPath('data.currency', 'ETB')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('milestones', [
            'contract_id' => $data['contract']->id,
            'title' => 'Phase 1: UI Design',
            'amount' => 15000,
        ]);
    }

    public function test_freelancer_cannot_create_milestone(): void
    {
        $data = $this->createContract();

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones', [
                'title' => 'Sneaky Milestone',
                'amount' => 5000,
            ])
            ->assertStatus(403);
    }

    public function test_milestone_validation_requires_title_and_amount(): void
    {
        $data = $this->createContract();

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'amount']);
    }

    public function test_milestone_etb_amount_must_be_positive(): void
    {
        $data = $this->createContract();

        foreach ([0, -500, -10000] as $amount) {
            $this->actingAsSanctum($data['employer'])
                ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones', [
                    'title' => 'Invalid Amount Milestone',
                    'amount' => $amount,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['amount']);
        }
    }

    public function test_employer_can_list_contract_milestones(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract']);
        $this->createMilestone($data['contract'], ['title' => 'Phase 2: API']);

        $response = $this->actingAsSanctum($data['employer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id . '/milestones');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_freelancer_can_list_contract_milestones(): void
    {
        $data = $this->createContract();
        $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['freelancer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id . '/milestones')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_employer_can_view_single_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['employer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $milestone->id);
    }

    public function test_milestone_not_found_for_wrong_contract(): void
    {
        $data = $this->createContract();
        $otherData = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($otherData['employer'])
            ->getJson('/api/v1/contracts/' . $otherData['contract']->id . '/milestones/' . $milestone->id)
            ->assertStatus(404);
    }

    public function test_freelancer_can_submit_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $response = $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/submit');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');

        $this->assertNotNull($response->json('data.submitted_at'));
        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'submitted',
        ]);
    }

    public function test_employer_cannot_submit_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/submit')
            ->assertStatus(403);
    }

    public function test_freelancer_cannot_submit_milestone_on_another_contract(): void
    {
        $data = $this->createContract();
        $otherData = $this->createContract();
        $milestone = $this->createMilestone($otherData['contract']);

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $otherData['contract']->id . '/milestones/' . $milestone->id . '/submit')
            ->assertStatus(403);
    }

    public function test_employer_can_approve_submitted_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/approve');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertNotNull($response->json('data.approved_at'));
        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'approved',
        ]);
    }

    public function test_employer_cannot_approve_unsubmitted_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']); // pending

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/approve')
            ->assertStatus(422);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'pending',
        ]);
    }

    public function test_freelancer_cannot_approve_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'submitted']);

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/approve')
            ->assertStatus(403);
    }

    public function test_employer_can_request_revision(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/revision', [
                'revision_note' => 'Please revise this section.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('revision_requested', $response->json('data.status'));
        $this->assertNull($response->json('data.submitted_at'));
    }

    public function test_invalid_milestone_transition_rejected(): void
    {
        $data = $this->createContract();

        // Revision on a pending milestone is invalid.
        $pending = $this->createMilestone($data['contract']);
        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $pending->id . '/revision')
            ->assertStatus(422);

        // Submitting an approved milestone is invalid.
        $approved = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $approved->id . '/submit')
            ->assertStatus(422);
    }

    public function test_employer_can_update_pending_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $response = $this->actingAsSanctum($data['employer'])
            ->putJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id, [
                'title' => 'Updated Title',
                'amount' => 20000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.amount', 20000);
    }

    public function test_employer_cannot_update_approved_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->putJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id, [
                'title' => 'Too Late',
            ])
            ->assertStatus(422);
    }

    public function test_freelancer_cannot_update_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['freelancer'])
            ->putJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id, [
                'title' => 'Hijack',
            ])
            ->assertStatus(403);
    }

    public function test_employer_can_delete_pending_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['employer'])
            ->deleteJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id)
            ->assertStatus(200);

        $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
    }

    public function test_employer_cannot_delete_started_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        $this->actingAsSanctum($data['employer'])
            ->deleteJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id)
            ->assertStatus(422);
    }

    public function test_cannot_create_milestone_on_paused_contract(): void
    {
        $data = $this->createContract(['status' => 'paused']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones', [
                'title' => 'Frozen',
                'amount' => 5000,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_submit_milestone_on_cancelled_contract(): void
    {
        $data = $this->createContract(['status' => 'cancelled']);
        $milestone = $this->createMilestone($data['contract']);

        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/submit')
            ->assertStatus(422);
    }

    public function test_cannot_approve_milestone_on_paused_contract(): void
    {
        $data = $this->createContract(['status' => 'paused']);
        $milestone = $this->createMilestone($data['contract'], ['status' => 'submitted']);

        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/approve')
            ->assertStatus(422);
    }

    public function test_milestone_response_omits_sensitive_data(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        $response = $this->actingAsSanctum($data['freelancer'])
            ->getJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id);

        $response->assertStatus(200);
        $payload = $response->json('data');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
    }

    public function test_full_milestone_workflow_end_to_end(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);

        // Freelancer submits.
        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/submit')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');

        // Employer requests revision -> status becomes revision_requested.
        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/revision', [
                'revision_note' => 'Please update responsive styles.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'revision_requested');

        // Freelancer submits again.
        $this->actingAsSanctum($data['freelancer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/submit')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');

        // Employer approves.
        $this->actingAsSanctum($data['employer'])
            ->postJson('/api/v1/contracts/' . $data['contract']->id . '/milestones/' . $milestone->id . '/approve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'approved',
        ]);
    }
}
