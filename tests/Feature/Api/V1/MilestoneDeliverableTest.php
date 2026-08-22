<?php

namespace Tests\Feature\Api\V1;

use App\Models\Milestone;
use App\Models\MilestoneSubmission;
use App\Models\Notification;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class MilestoneDeliverableTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_freelancer_can_submit_work_with_description_files_and_links(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        $file1 = UploadedFile::fake()->create('project_report.pdf', 500, 'application/pdf');
        $file2 = UploadedFile::fake()->image('preview.png', 800, 600);

        $response = $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Completed the full initial milestone delivery.',
                'links' => [
                    'https://github.com/dreammore/ecommerce-api',
                    'https://figma.com/design/sample-project',
                ],
                'files' => [$file1, $file2],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submissions_count', 1);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('milestone_submissions', [
            'milestone_id' => $milestone->id,
            'submitted_by' => $data['freelancer']->id,
            'description' => 'Completed the full initial milestone delivery.',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('milestone_submission_files', [
            'original_filename' => 'project_report.pdf',
            'uploader_id' => $data['freelancer']->id,
        ]);

        $this->assertDatabaseHas('milestone_submission_files', [
            'original_filename' => 'preview.png',
            'uploader_id' => $data['freelancer']->id,
        ]);

        // Employer receives milestone_submitted notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $data['employer']->id,
            'type' => 'milestone_submitted',
        ]);
    }

    public function test_freelancer_cannot_submit_executable_files(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        $maliciousFile = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Deliverable with invalid file',
                'files' => [$maliciousFile],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_employer_can_view_milestone_submissions_history(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        // First submission
        $file = UploadedFile::fake()->create('design.pdf', 200, 'application/pdf');
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Revision 1 draft',
                'files' => [$file],
            ])
            ->assertStatus(200);

        // Employer views submissions history
        $response = $this->actingAsSanctum($data['employer'])
            ->getJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submissions");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Revision 1 draft')
            ->assertJsonCount(1, 'data.0.files');
    }

    public function test_employer_can_request_revision_with_feedback_note(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        $file = UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf');
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'First attempt',
                'files' => [$file],
            ])
            ->assertStatus(200);

        // Employer requests revision
        $response = $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/revision", [
                'revision_note' => 'Please fix the database indexes and optimize queries.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'revision_requested');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'revision_requested',
        ]);

        $this->assertDatabaseHas('milestone_submissions', [
            'milestone_id' => $milestone->id,
            'status' => 'revision_requested',
            'revision_note' => 'Please fix the database indexes and optimize queries.',
            'reviewed_by' => $data['employer']->id,
        ]);

        // Freelancer receives milestone_revision notification with note
        $this->assertDatabaseHas('notifications', [
            'user_id' => $data['freelancer']->id,
            'type' => 'milestone_revision',
        ]);
    }

    public function test_freelancer_can_resubmit_work_after_revision_request_and_preserve_history(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        // First submission
        $file1 = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Initial Version 1',
                'files' => [$file1],
            ])
            ->assertStatus(200);

        // Employer requests revision
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/revision", [
                'revision_note' => 'Update the styling',
            ])
            ->assertStatus(200);

        // Freelancer resubmits (Revision 2)
        $file2 = UploadedFile::fake()->create('v2.pdf', 150, 'application/pdf');
        $response = $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Updated Version 2 with new styling',
                'files' => [$file2],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submissions_count', 2);

        // Submissions history has both revisions
        $historyResponse = $this->actingAsSanctum($data['employer'])
            ->getJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submissions");

        $historyResponse->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_employer_approving_all_milestones_auto_completes_contract_and_job(): void
    {
        $data = $this->createContract();
        // Create 2 milestones
        $m1 = $this->createMilestone($data['contract'], ['title' => 'Milestone 1', 'amount' => 20000, 'status' => 'approved', 'approved_at' => now()]);
        $m2 = $this->createMilestone($data['contract'], ['title' => 'Milestone 2', 'amount' => 25000, 'status' => 'in_progress']);

        // Freelancer submits milestone 2
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$m2->id}/submit", [
                'description' => 'Final milestone deliverables.',
            ])
            ->assertStatus(200);

        // Employer approves milestone 2 (the final remaining milestone)
        $response = $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$m2->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Contract and Job should be auto-completed
        $this->assertDatabaseHas('contracts', [
            'id' => $data['contract']->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $data['job']->id,
            'status' => 'completed',
        ]);

        // Completion notifications sent
        $this->assertDatabaseHas('notifications', [
            'user_id' => $data['freelancer']->id,
            'type' => 'contract_completed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $data['employer']->id,
            'type' => 'contract_completed',
        ]);

        // Rating/review now available for both parties
        $reviewResponse = $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/review", [
                'rating' => 5,
                'comment' => 'Outstanding work delivered on time!',
            ]);

        $reviewResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('reviews', [
            'contract_id' => $data['contract']->id,
            'reviewer_id' => $data['employer']->id,
            'rating' => 5,
        ]);
    }

    public function test_authenticated_user_can_securely_download_deliverable_file(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);

        $file = UploadedFile::fake()->create('source_code.zip', 1024, 'application/zip');
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Source code submission',
                'files' => [$file],
            ])
            ->assertStatus(200);

        $submission = MilestoneSubmission::where('milestone_id', $milestone->id)->first();
        $submissionFile = $submission->files()->first();

        // Employer downloads file
        $downloadResponse = $this->actingAsSanctum($data['employer'])
            ->get("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submissions/{$submission->id}/files/{$submissionFile->id}/download");

        $downloadResponse->assertStatus(200);

        // Third party user denied access
        $stranger = $this->createContractUser('freelancer');
        $this->actingAsSanctum($stranger)
            ->get("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submissions/{$submission->id}/files/{$submissionFile->id}/download")
            ->assertStatus(403);
    }
}
