<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CombinedCredentialVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $verifyPerm = Permission::firstOrCreate(
            ['slug' => 'users.verify'],
            ['name' => 'Verify Users', 'group' => 'users']
        );

        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $superAdminRole->permissions()->syncWithoutDetaching([$verifyPerm->id]);

        $this->freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->admin->adminRoles()->attach($superAdminRole->id);
    }

    public function test_submitting_credential_with_identity_doc_issues_single_merged_admin_notification(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        $certDoc = UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf');
        $idDoc   = UploadedFile::fake()->create('national_id.jpg', 150, 'image/jpeg');

        $response = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/credentials', [
                'title' => 'Senior Developer Certificate',
                'type' => 'external_certificate',
                'issuing_organization' => 'Tech Academy',
                'document' => $certDoc,
                'verification_document' => $idDoc,
                'verification_notes' => 'Here is my ID document for identity verification',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // Verify Credential created
        $this->assertDatabaseHas('credentials', [
            'user_id' => $this->freelancer->id,
            'title' => 'Senior Developer Certificate',
            'status' => 'pending',
        ]);

        // Verify Verification created
        $this->assertDatabaseHas('verifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'identity',
            'status' => 'pending',
        ]);

        // Verify EXACTLY ONE merged notification created for admin
        $notifications = Notification::where('user_id', $this->admin->id)->get();
        $this->assertCount(1, $notifications);

        $this->assertEquals('credential_submitted', $notifications->first()->type);
        $this->assertEquals('New Credential & Identity Document Submitted', $notifications->first()->title);
        $this->assertStringContainsString('identity verification document', $notifications->first()->message);
    }

    public function test_approving_credential_automatically_approves_identity_verification_so_admin_only_approves_once(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        $certDoc = UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf');
        $idDoc   = UploadedFile::fake()->create('national_id.jpg', 150, 'image/jpeg');

        $submitRes = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/credentials', [
                'title' => 'AWS Certified Solutions Architect',
                'type' => 'external_certificate',
                'issuing_organization' => 'Amazon',
                'document' => $certDoc,
                'verification_document' => $idDoc,
            ]);

        $credentialId = $submitRes->json('data.id');

        // Admin checks pending verifications list — receives single merged item
        $listRes = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/verifications?status=pending');

        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('data'));

        // Admin approves the credential ONCE
        $approveRes = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/credentials/{$credentialId}/approve");

        $approveRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // BOTH Credential and Verification are now approved in database
        $this->assertDatabaseHas('credentials', [
            'id' => $credentialId,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('verifications', [
            'user_id' => $this->freelancer->id,
            'status' => 'approved',
        ]);
    }
}
