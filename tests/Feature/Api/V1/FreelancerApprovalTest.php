<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreelancerApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employer;
    private User $freelancer;
    private string $adminToken;
    private string $employerToken;
    private string $freelancerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        // Assign super_admin role so the admin has full access in tests.
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->employer = User::create([
            'name'     => 'Employer User',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $this->employerToken = $this->employer->createToken('employer_token')->plainTextToken;

        $this->freelancer = User::create([
            'name'     => 'Freelancer User',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $this->freelancerToken = $this->freelancer->createToken('freelancer_token')->plainTextToken;

        FreelancerProfile::create([
            'user_id' => $this->freelancer->id,
            'approval_status' => 'pending',
        ]);
    }

    // ─── ADMIN ACCESS CONTROL ───────────────────────────────────────

    public function test_unauthenticated_user_cannot_list_freelancers(): void
    {
        $this->getJson('/api/v1/admin/freelancers')
             ->assertStatus(401);
    }

    public function test_employer_cannot_list_freelancers(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
             ->getJson('/api/v1/admin/freelancers')
             ->assertStatus(403);
    }

    public function test_freelancer_cannot_list_freelancers(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->getJson('/api/v1/admin/freelancers')
             ->assertStatus(403);
    }

    // ─── ADMIN LIST FREELANCERS ─────────────────────────────────────

    public function test_admin_can_list_freelancers(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/freelancers')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'success', 'message', 'data',
             ]);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/freelancers?status=pending')
             ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/freelancers?status=approved')
             ->assertStatus(200);
    }

    // ─── ADMIN APPROVE FREELANCER ───────────────────────────────────

    public function test_admin_can_approve_freelancer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/approve");

        $response->assertStatus(200)
                 ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $this->freelancer->id,
            'approval_status' => 'approved',
        ]);
    }

    public function test_approved_freelancer_is_visible_in_marketplace(): void
    {
        // Approve the freelancer
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/approve")
             ->assertStatus(200);

        // Should now appear in public listing
        $this->getJson('/api/v1/freelancers')
             ->assertStatus(200)
             ->assertJsonFragment(['user_id' => $this->freelancer->id]);
    }

    // ─── ADMIN REJECT FREELANCER ────────────────────────────────────

    public function test_admin_can_reject_freelancer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/reject", [
                             'reason' => 'Incomplete profile',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.approval_status', 'rejected');

        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $this->freelancer->id,
            'approval_status' => 'rejected',
            'rejection_reason' => 'Incomplete profile',
        ]);
    }

    public function test_rejected_freelancer_is_not_visible_in_marketplace(): void
    {
        // Reject the freelancer
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/reject", [
                 'reason' => 'Not qualified',
             ])
             ->assertStatus(200);

        // Should NOT appear in public listing
        $this->getJson('/api/v1/freelancers')
             ->assertStatus(200)
             ->assertJsonMissing(['user_id' => $this->freelancer->id]);
    }

    // ─── PENDING FREELANCER VISIBILITY ──────────────────────────────

    public function test_pending_freelancer_is_not_visible_in_marketplace(): void
    {
        // Freelancer is pending by default
        $this->getJson('/api/v1/freelancers')
             ->assertStatus(200)
             ->assertJsonMissing(['user_id' => $this->freelancer->id]);
    }

    public function test_pending_freelancer_is_not_in_search(): void
    {
        $category = Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-' . Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . Str::random(4), 'category_id' => $category->id]);

        $this->freelancer->freelancerProfile->update([
            'headline' => 'Laravel Developer',
        ]);

        $this->getJson('/api/v1/search?q=laravel')
             ->assertStatus(200)
             ->assertJsonPath('data.freelancers_total', 0);
    }

    public function test_pending_freelancer_is_not_in_recommendations(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
             ->getJson('/api/v1/recommendations/freelancers')
             ->assertStatus(200)
             ->assertJsonPath('data', []);
    }

    // ─── PROPOSAL BLOCKING ──────────────────────────────────────────

    public function test_pending_freelancer_cannot_submit_proposal(): void
    {
        $category = Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-' . Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . Str::random(4), 'category_id' => $category->id]);

        $job = \App\Models\Job::create([
            'employer_id' => $this->employer->id,
            'title'       => 'Test Job',
            'slug'        => 'test-job-' . Str::random(4),
            'description' => 'A test job.',
            'status'      => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                 'cover_letter'       => 'I can do this.',
                 'bid_amount'         => 1000,
                 'estimated_duration' => '1 week',
             ])
             ->assertStatus(403)
             ->assertJson(['message' => 'Your account is pending admin approval. You cannot submit proposals until your profile is approved.']);
    }

    public function test_approved_freelancer_can_submit_proposal(): void
    {
        $category = Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-' . Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . Str::random(4), 'category_id' => $category->id]);

        // Create approved credential
        \App\Models\Credential::create([
            'user_id'    => $this->freelancer->id,
            'title'      => 'ID Document',
            'type'       => 'external_certificate',
            'file_path'  => 'credentials/test.pdf',
            'status'     => 'approved',
        ]);

        // Approve the freelancer
        $this->freelancer->freelancerProfile->approve();

        $job = \App\Models\Job::create([
            'employer_id' => $this->employer->id,
            'title'       => 'Test Job',
            'slug'        => 'test-job-' . Str::random(4),
            'description' => 'A test job.',
            'status'      => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                 'cover_letter'       => 'I can do this.',
                 'bid_amount'         => 1000,
                 'estimated_duration' => '1 week',
             ])
             ->assertStatus(201)
             ->assertJson(['success' => true]);
    }

    // ─── NOTIFICATIONS ──────────────────────────────────────────────

    public function test_freelancer_receives_approval_notification(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/approve")
             ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'freelancer_approved',
        ]);
    }

    public function test_freelancer_receives_rejection_notification(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson("/api/v1/admin/freelancers/{$this->freelancer->freelancerProfile->id}/reject", [
                 'reason' => 'Not qualified',
             ])
             ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->freelancer->id,
            'type' => 'freelancer_rejected',
        ]);
    }

    public function test_admins_receive_notification_on_new_freelancer_registration(): void
    {
        // Register a new freelancer through the API (triggers AuthService)
        $this->postJson('/api/v1/auth/register', [
            'name'     => 'New Freelancer',
            'email'    => 'new@test.com',
            'password' => 'password123',
            'role'     => 'freelancer',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'new_freelancer_registration',
        ]);
    }

    // ─── PUBLIC PROFILE ─────────────────────────────────────────────

    public function test_pending_freelancer_profile_is_not_public(): void
    {
        $this->getJson("/api/v1/freelancers/{$this->freelancer->id}")
             ->assertStatus(404);
    }

    public function test_approved_freelancer_profile_is_public(): void
    {
        $this->freelancer->freelancerProfile->approve();

        $this->getJson("/api/v1/freelancers/{$this->freelancer->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_credential_approval_refreshes_verification_and_allows_proposal(): void
    {
        $category = Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-' . Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . Str::random(4), 'category_id' => $category->id]);

        $credential = \App\Models\Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'ID Document',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);
        $this->freelancer->freelancerProfile->approve();

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson("/api/v1/admin/credentials/{$credential->id}/approve")
             ->assertOk()
             ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('credentials', [
            'id' => $credential->id,
            'user_id' => $this->freelancer->id,
            'status' => 'approved',
        ]);

        // Verify the model directly sees both approvals
        $freshUser = \App\Models\User::find($this->freelancer->id);
        $this->assertTrue($freshUser->hasApprovedCredentials(), 'hasApprovedCredentials should return true after credential approval');
        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $this->freelancer->id,
            'approval_status' => 'approved',
        ]);

        // Verify the combined is_fully_approved check works on the model
        $profile = $freshUser->freelancerProfile;
        $this->assertNotNull($profile, 'freelancer profile should exist');
        $this->assertEquals('approved', $profile->approval_status);
        $this->assertTrue($freshUser->hasApprovedCredentials());

        // Verify the UserResource exposes is_fully_approved correctly
        \App\Models\User::unguarded(function () use ($freshUser) {
            $freshUser->load('freelancerProfile');
            $resource = new \App\Http\Resources\Api\V1\UserResource($freshUser);
            $data = $resource->toArray(request());
            $this->assertTrue($data['is_fully_approved'], 'is_fully_approved should be true when both profile and credentials are approved');
            $this->assertTrue($data['is_verified']);
            $this->assertTrue($data['has_approved_credentials']);
            $this->assertEquals('approved', $data['verification_status']);
            $this->assertEquals('approved', $data['profile_approval_status']);
        });

        // Verify proposal submission works (the real end-to-end test)
        $job = \App\Models\Job::create([
            'employer_id' => $this->employer->id,
            'title' => 'Approved Credential Job',
            'slug' => 'approved-credential-job-' . Str::random(4),
            'description' => 'A test job.',
            'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                 'cover_letter' => 'I can do this.',
                 'bid_amount' => 1000,
                 'estimated_duration' => '1 week',
             ])
             ->assertStatus(201)
             ->assertJson(['success' => true]);
    }

    public function test_fully_approved_field_matches_middleware_dual_check(): void
    {
        // Scenario 1: Profile approved, no credentials → not fully approved
        \App\Models\Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'ID Document',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending', // NOT approved
        ]);
        $this->freelancer->freelancerProfile->approve();

        $user = \App\Models\User::find($this->freelancer->id)->load('freelancerProfile');
        $resource = new \App\Http\Resources\Api\V1\UserResource($user);
        $data = $resource->toArray(request());
        $this->assertFalse($data['is_fully_approved'], 'Should NOT be fully approved when credentials are not approved');

        // Scenario 2: Both approved → fully approved
        $user->credentials()->where('status', 'pending')->update(['status' => 'approved']);
        $user->refresh()->load('freelancerProfile');
        $resource2 = new \App\Http\Resources\Api\V1\UserResource($user);
        $data2 = $resource2->toArray(request());
        $this->assertTrue($data2['is_fully_approved'], 'Should be fully approved when both profile and credentials are approved');

        // Scenario 3: Profile pending, credentials approved → not fully approved
        $user->freelancerProfile->update(['approval_status' => 'pending']);
        $user->refresh()->load('freelancerProfile');
        $resource3 = new \App\Http\Resources\Api\V1\UserResource($user);
        $data3 = $resource3->toArray(request());
        $this->assertFalse($data3['is_fully_approved'], 'Should NOT be fully approved when profile is pending');
    }
}
