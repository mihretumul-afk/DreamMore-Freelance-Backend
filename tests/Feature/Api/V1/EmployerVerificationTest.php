<?php

namespace Tests\Feature\Api\V1;

use App\Models\EmployerProfile;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployerVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $employer;
    private User $admin;
    private EmployerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $this->profile = EmployerProfile::create([
            'user_id' => $this->employer->id,
            'company_name' => 'Acme Tech',
            'industry' => 'Technology',
            'location' => 'Addis Ababa',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_employer_can_submit_company_verification(): void
    {
        Storage::fake('public');

        $doc = UploadedFile::fake()->create('company_reg.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->employer)
            ->postJson('/api/v1/verifications', [
                'type' => 'company_registration',
                'notes' => 'Commercial registration certificate',
                'document' => $doc,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'company_registration')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('verifications', [
            'user_id' => $this->employer->id,
            'type' => 'company_registration',
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_approve_employer_verification(): void
    {
        $verification = Verification::create([
            'user_id' => $this->employer->id,
            'type' => 'company_registration',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/verifications/{$verification->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertEquals('approved', $verification->fresh()->status);
        $this->assertEquals($this->admin->id, $verification->fresh()->reviewed_by);

        // Employer received notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type' => 'verification_approved',
        ]);
    }

    public function test_admin_can_reject_employer_verification_with_reason(): void
    {
        $verification = Verification::create([
            'user_id' => $this->employer->id,
            'type' => 'company_registration',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/verifications/{$verification->id}/reject", [
                'reason' => 'Expired business license. Please upload renewed document.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Expired business license. Please upload renewed document.');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employer->id,
            'type' => 'verification_rejected',
        ]);
    }
}
