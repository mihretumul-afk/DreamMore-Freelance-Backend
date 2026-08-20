<?php

namespace Tests\Feature\Api\V1;

use App\Models\Credential;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialTest extends TestCase
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
        return User::create([
            'name' => 'Test Admin',
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);
    }

    public function test_freelancer_can_create_credential(): void
    {
        Storage::fake('private');
        $freelancer = $this->createFreelancer();
        $token = $freelancer->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/credentials', [
                'title' => 'Web Development Certificate',
                'type' => 'dream_more_certificate',
                'issuing_organization' => 'Dream More',
                'certificate_identifier' => 'DM-WD-2024-001',
                'issue_date' => '2024-01-15',
                'document' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Credential submitted successfully.',
            ])
            ->assertJsonStructure(['data' => ['id', 'title', 'type', 'status']]);

        $this->assertDatabaseHas('credentials', [
            'user_id' => $freelancer->id,
            'title' => 'Web Development Certificate',
            'type' => 'dream_more_certificate',
            'status' => 'pending',
        ]);
    }

    public function test_credential_starts_as_pending(): void
    {
        Storage::fake('private');
        $freelancer = $this->createFreelancer();
        $token = $freelancer->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/credentials', [
                'title' => 'External Certificate',
                'type' => 'external_certificate',
                'document' => $file,
            ]);

        $response->assertStatus(201);
        $this->assertEquals('pending', $response->json('data.status'));
    }

    public function test_freelancer_can_view_own_credentials(): void
    {
        $freelancer = $this->createFreelancer();
        $token = $freelancer->createToken('test')->plainTextToken;

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'My Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/credentials');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_freelancer_cannot_approve_own_credential(): void
    {
        $freelancer = $this->createFreelancer();
        $token = $freelancer->createToken('test')->plainTextToken;

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'My Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/credentials/{$credential->id}/approve");

        $response->assertForbidden();
    }

    public function test_admin_can_approve_credential(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Verified Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/credentials/{$credential->id}/approve");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Credential approved successfully.',
            ]);

        $this->assertDatabaseHas('credentials', [
            'id' => $credential->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        // Notification should be created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'credential_approved',
        ]);
    }

    public function test_admin_can_reject_credential_with_reason(): void
    {
        $freelancer = $this->createFreelancer();
        $admin = $this->createAdmin();
        $token = $admin->createToken('test')->plainTextToken;

        $credential = Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Fake Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/admin/credentials/{$credential->id}/reject", [
                'reason' => 'Document appears to be altered',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('credentials', [
            'id' => $credential->id,
            'status' => 'rejected',
            'rejection_reason' => 'Document appears to be altered',
        ]);

        // Notification should be created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'credential_rejected',
        ]);
    }

    public function test_public_profile_does_not_expose_private_document_path(): void
    {
        $freelancer = $this->createFreelancer();

        FreelancerProfile::create(['user_id' => $freelancer->id]);

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Secret Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/secret.pdf',
            'status' => 'approved',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$freelancer->id}");

        $response->assertOk();

        // The response should contain verified_credentials but not file_path
        $data = $response->json('data');
        $this->assertArrayHasKey('verified_credentials', $data);

        if (!empty($data['verified_credentials'])) {
            foreach ($data['verified_credentials'] as $cred) {
                $this->assertArrayNotHasKey('file_path', $cred);
                $this->assertArrayNotHasKey('file_original_name', $cred);
            }
        }
    }

    public function test_employer_cannot_create_credential(): void
    {
        Storage::fake('private');
        $employer = User::create([
            'name' => 'Test Employer',
            'email' => 'employer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);
        $token = $employer->createToken('test')->plainTextToken;

        $file = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/credentials', [
                'title' => 'Certificate',
                'type' => 'external_certificate',
                'document' => $file,
            ]);

        $response->assertForbidden();
    }

    public function test_only_approved_credentials_shown_publicly(): void
    {
        $freelancer = $this->createFreelancer();

        // Approved credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Approved Cert',
            'type' => 'dream_more_certificate',
            'file_path' => 'credentials/approved.pdf',
            'status' => 'approved',
        ]);

        // Pending credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Pending Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/pending.pdf',
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$freelancer->id}/credentials");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Approved Cert', $data[0]['title']);
    }

    public function test_credential_validation_requires_title_and_document(): void
    {
        $freelancer = $this->createFreelancer();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/credentials', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'document']);
    }
}
