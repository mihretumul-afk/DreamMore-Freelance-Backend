<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class MilestoneFileShareTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    /**
     * Storage directories (per milestone) created during the test run.
     * FileController serves files from the real public disk, so uploads are
     * written there and cleaned up afterwards.
     *
     * @var array<int, string>
     */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirs as $dir) {
            Storage::disk('public')->deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function makeToken($user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function authenticatedFileUrl(string $storedPath, string $type, $user): string
    {
        $cleanPath = preg_replace('#^milestone-(submissions|attachments)/#', '', $storedPath);

        return "/api/v1/milestone-{$type}/{$cleanPath}?token=" . $this->makeToken($user);
    }

    public function test_freelancer_can_see_and_download_reference_files_from_contract(): void
    {
        $data = $this->createContract();

        // Employer creates a milestone with a reference file
        $file = UploadedFile::fake()->create('brand-guidelines.pdf', 500, 'application/pdf');
        $createResponse = $this->actingAsSanctum($data['employer'])
            ->post("/api/v1/contracts/{$data['contract']->id}/milestones", [
                'title'  => 'Design Homepage',
                'amount' => 15000,
                'files'  => [$file],
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attachments.0.name', 'brand-guidelines.pdf');

        $milestoneId = $createResponse->json('data.id');
        $this->cleanupDirs[] = "milestone-attachments/{$milestoneId}";

        // Freelancer opens the contract and sees the reference file
        $response = $this->actingAsSanctum($data['freelancer'])
            ->getJson("/api/v1/contracts/{$data['contract']->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.milestones.0.attachments.0.name', 'brand-guidelines.pdf');

        $path = $response->json('data.milestones.0.attachments.0.path');
        $this->assertNotNull($path);
        $this->assertStringStartsWith('milestone-attachments/', $path);

        // Freelancer can download the reference file
        $this->get($this->authenticatedFileUrl($path, 'attachments', $data['freelancer']))
            ->assertStatus(200);

        // A stranger cannot download it
        $stranger = $this->createContractUser('freelancer');
        $this->get($this->authenticatedFileUrl($path, 'attachments', $stranger))
            ->assertStatus(403);
    }

    public function test_employer_can_see_links_and_download_submitted_work_files(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);
        $this->cleanupDirs[] = "milestone-submissions/{$milestone->id}";

        // Freelancer submits work with a link and a file
        $file = UploadedFile::fake()->create('final-design.png', 800, 'image/png');
        $this->actingAsSanctum($data['freelancer'])
            ->post("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Homepage design delivered.',
                'links'       => ['https://github.com/dreammore/homepage'],
                'files'       => [$file],
            ])
            ->assertStatus(200);

        // Employer opens the contract and sees the link + uploaded file
        $response = $this->actingAsSanctum($data['employer'])
            ->getJson("/api/v1/contracts/{$data['contract']->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.milestones.0.submissions.0.links.0', 'https://github.com/dreammore/homepage')
            ->assertJsonPath('data.milestones.0.submissions.0.files.0.name', 'final-design.png');

        $path = $response->json('data.milestones.0.submissions.0.files.0.path');
        $this->assertNotNull($path);
        $this->assertStringStartsWith('milestone-submissions/', $path);

        // Employer can download the submitted file
        $this->get($this->authenticatedFileUrl($path, 'submissions', $data['employer']))
            ->assertStatus(200);

        // A stranger cannot download it
        $stranger = $this->createContractUser('employer');
        $this->get($this->authenticatedFileUrl($path, 'submissions', $stranger))
            ->assertStatus(403);
    }

    public function test_employer_can_still_download_submitted_files_after_approving_milestone(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], ['status' => 'in_progress']);
        $this->cleanupDirs[] = "milestone-submissions/{$milestone->id}";

        $file = UploadedFile::fake()->create('source-code.zip', 1024, 'application/zip');
        $this->actingAsSanctum($data['freelancer'])
            ->post("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Final source code.',
                'files'       => [$file],
            ])
            ->assertStatus(200);

        // Employer approves the milestone
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Submission (with file) is still visible in the contract after approval
        $response = $this->actingAsSanctum($data['employer'])
            ->getJson("/api/v1/contracts/{$data['contract']->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.milestones.0.status', 'approved')
            ->assertJsonCount(1, 'data.milestones.0.submissions');

        $path = $response->json('data.milestones.0.submissions.0.files.0.path');
        $this->assertNotNull($path);

        // Employer can still download the file after approval
        $this->get($this->authenticatedFileUrl($path, 'submissions', $data['employer']))
            ->assertStatus(200);
    }
}