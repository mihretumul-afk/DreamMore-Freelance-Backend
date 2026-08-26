<?php

namespace Tests\Feature\Api\V1;

use App\Models\Credential;
use App\Models\FreelancerProfile;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialAdvancedTest extends TestCase
{
    use RefreshDatabase;

    private function createFreelancer(): User
    {
        return User::create([
            'name' => 'Test Freelancer',
            'email' => 'freelancer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);
    }

    private function createAdmin(): User
    {
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $admin->adminRoles()->attach($superAdminRole->id);
        return $admin;
    }

    private function createEmployer(): User
    {
        return User::create([
            'name' => 'Test Employer',
            'email' => 'employer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = $user->createToken('test')->plainTextToken;
        return ['Authorization' => "Bearer {$token}"];
    }

    // ─── Update ──────────────────────────────────────────────────────

    public function test_freelancer_can_update_pending_credential(): void
    {
        $freelancer = $this->createFreelancer();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Old Title',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($freelancer, 'sanctum')
            ->putJson("/api/v1/credentials/{$credential->id}", [
                'title' => 'Updated Title',
                'description' => 'A new description for this credential.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');

        $this->assertDatabaseHas('credentials', [
            'id' => $credential->id,
            'title' => 'Updated Title',
            'description' => 'A new description for this credential.',
        ]);
    }

    public function test_cannot_update_approved_credential(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Approved Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($freelancer, 'sanctum')
            ->putJson("/api/v1/credentials/{$credential->id}", [
                'title' => 'Hacked Title',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_update_another_freelancers_credential(): void
    {
        $freelancer1 = $this->createFreelancer();
        $freelancer2 = $this->createFreelancer();

        $credential = Credential::create([
            'user_id' => $freelancer1->id,
            'title' => 'My Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($freelancer2, 'sanctum')
            ->putJson("/api/v1/credentials/{$credential->id}", [
                'title' => 'Stolen Cert',
            ]);

        $response->assertForbidden();
    }

    // ─── Description ─────────────────────────────────────────────────

    public function test_description_is_stored_on_create(): void
    {
        Storage::fake('private');
        $freelancer = $this->createFreelancer();
        $file = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

        $response = $this->actingAs($freelancer, 'sanctum')
            ->postJson('/api/v1/credentials', [
                'title' => 'Described Cert',
                'type' => 'external_certificate',
                'description' => 'This is a detailed description of the credential.',
                'document' => $file,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('credentials', [
            'user_id' => $freelancer->id,
            'description' => 'This is a detailed description of the credential.',
        ]);
    }

    // ─── Cross-user access ──────────────────────────────────────────

    public function test_freelancer_cannot_view_another_freelancers_credential(): void
    {
        $freelancer1 = $this->createFreelancer();
        $freelancer2 = $this->createFreelancer();

        $credential = Credential::create([
            'user_id' => $freelancer1->id,
            'title' => 'Private Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($freelancer2, 'sanctum')
            ->getJson("/api/v1/credentials/{$credential->id}");

        $response->assertForbidden();
    }

    public function test_employer_cannot_list_credentials(): void
    {
        $employer = $this->createEmployer();

        $response = $this->actingAs($employer, 'sanctum')
            ->getJson('/api/v1/credentials');

        $response->assertForbidden();
    }

    public function test_employer_cannot_create_credential(): void
    {
        Storage::fake('private');
        $employer = $this->createEmployer();
        $file = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

        $response = $this->actingAs($employer, 'sanctum')
            ->postJson('/api/v1/credentials', [
                'title' => 'Fake Cert',
                'type' => 'external_certificate',
                'document' => $file,
            ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_credentials(): void
    {
        $freelancer = $this->createFreelancer();
        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Private Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $this->getJson('/api/v1/credentials')->assertStatus(401);
        $this->getJson("/api/v1/credentials/{$credential->id}")->assertStatus(401);
    }

    // ─── Document protection ────────────────────────────────────────

    public function test_private_document_is_protected(): void
    {
        $freelancer = $this->createFreelancer();
        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Private Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/secret.pdf',
            'file_original_name' => 'secret.pdf',
            'status' => 'pending',
        ]);

        // Another freelancer cannot download
        $freelancer2 = $this->createFreelancer();
        $response = $this->actingAs($freelancer2, 'sanctum')
            ->getJson("/api/v1/credentials/{$credential->id}/download");

        $response->assertForbidden();
    }

    public function test_public_profile_does_not_expose_file_paths(): void
    {
        $freelancer = $this->createFreelancer();
        FreelancerProfile::create(['user_id' => $freelancer->id]);

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Secret Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/secret.pdf',
            'status' => 'approved',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$freelancer->id}");

        $response->assertOk();
        $data = $response->json('data');

        foreach ($data['verified_credentials'] as $cred) {
            $this->assertArrayNotHasKey('file_path', $cred);
            $this->assertArrayNotHasKey('file_original_name', $cred);
        }
    }

    // ─── Admin audit trail ──────────────────────────────────────────

    public function test_admin_approve_records_reviewer_and_timestamp(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Audit Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/approve");

        $response->assertOk();

        $credential->refresh();
        $this->assertEquals($admin->id, $credential->reviewed_by);
        $this->assertNotNull($credential->reviewed_at);
    }

    public function test_admin_reject_records_reason_and_audit_trail(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Rejected Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/reject", [
                'reason' => 'Document appears forged',
            ]);

        $response->assertOk();

        $credential->refresh();
        $this->assertEquals('rejected', $credential->status);
        $this->assertEquals('Document appears forged', $credential->rejection_reason);
        $this->assertEquals($admin->id, $credential->reviewed_by);
        $this->assertNotNull($credential->reviewed_at);
    }

    // ─── Notifications ──────────────────────────────────────────────

    public function test_approve_creates_notification(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Notified Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/approve");

        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'credential_approved',
        ]);
    }

    public function test_reject_creates_notification_with_reason(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Rejected Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/reject", [
                'reason' => 'Invalid document',
            ]);

        $notification = Notification::where('user_id', $freelancer->id)
            ->where('type', 'credential_rejected')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Invalid document', $notification->message);
    }

    // ─── Employer cannot approve/reject ─────────────────────────────

    public function test_employer_cannot_approve_credential(): void
    {
        $freelancer = $this->createFreelancer();
        $employer = $this->createEmployer();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Test Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($employer, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/approve");

        $response->assertForbidden();
    }

    public function test_employer_cannot_reject_credential(): void
    {
        $freelancer = $this->createFreelancer();
        $employer = $this->createEmployer();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Test Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($employer, 'sanctum')
            ->putJson("/api/v1/admin/credentials/{$credential->id}/reject", [
                'reason' => 'No',
            ]);

        $response->assertForbidden();
    }

    // ─── Type enum ──────────────────────────────────────────────────

    public function test_credential_types_are_validated(): void
    {
        Storage::fake('private');
        $freelancer = $this->createFreelancer();
        $file = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

        $response = $this->actingAs($freelancer, 'sanctum')
            ->postJson('/api/v1/credentials', [
                'title' => 'Test Cert',
                'type' => 'invalid_type',
                'document' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ─── Delete protection ──────────────────────────────────────────

    public function test_cannot_delete_approved_credential(): void
    {
        $freelancer = $this->createFreelancer();

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Approved Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($freelancer, 'sanctum')
            ->deleteJson("/api/v1/credentials/{$credential->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    // ─── Public credentials endpoint ────────────────────────────────

    public function test_public_credentials_only_shows_approved(): void
    {
        $freelancer = $this->createFreelancer();

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Approved',
            'type' => 'external_certificate',
            'file_path' => 'credentials/approved.pdf',
            'status' => 'approved',
        ]);

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Pending',
            'type' => 'external_certificate',
            'file_path' => 'credentials/pending.pdf',
            'status' => 'pending',
        ]);

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Rejected',
            'type' => 'external_certificate',
            'file_path' => 'credentials/rejected.pdf',
            'status' => 'rejected',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$freelancer->id}/credentials");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Approved', $data[0]['title']);
    }
}
