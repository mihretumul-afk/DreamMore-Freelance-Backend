<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerificationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        // Assign super_admin role so the admin has full access in tests.
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
    }

    public function test_freelancer_can_submit_verification(): void
    {
        Storage::fake('public');

        $doc = UploadedFile::fake()->create('national_id.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/verifications', [
                'type' => 'national_id',
                'notes' => 'Here is my national ID document.',
                'document' => $doc,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'national_id');

        $this->assertDatabaseHas('verifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'national_id',
            'status' => 'pending',
        ]);

        // Admin received notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'admin_verification_submitted',
        ]);
    }

    public function test_freelancer_can_view_own_verification_status(): void
    {
        Verification::create([
            'user_id' => $this->freelancer->id,
            'type' => 'identity',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->getJson('/api/v1/verifications/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');
    }
}
