<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Category;
use App\Models\Contract;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Skill;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTest extends TestCase
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
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        // Assign super_admin role so the admin has full access in tests.
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
        $this->employer = User::create([
            'name' => 'Employer User',
            'email' => 'employer@test.com',
            'password' => bcrypt('password'),
            'role' => 'employer',
            'status' => 'active',
        ]);
        $this->employerToken = $this->employer->createToken('employer_token')->plainTextToken;

        $this->freelancer = User::create([
            'name' => 'Freelancer User',
            'email' => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role' => 'freelancer',
            'status' => 'active',
        ]);
        $this->freelancerToken = $this->freelancer->createToken('freelancer_token')->plainTextToken;
    }

    // ─── ACCESS CONTROL ────────────────────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    public function test_employer_cannot_access_admin_endpoints(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
             ->getJson('/api/v1/admin/dashboard')
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    public function test_freelancer_cannot_access_admin_endpoints(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->getJson('/api/v1/admin/dashboard')
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/dashboard')
             ->assertStatus(200)
             ->assertJson([
                 'success' => true,
                 'message' => 'Admin dashboard retrieved successfully.',
             ])
             ->assertJsonStructure([
                 'data' => [
                     'total_users', 'total_freelancers', 'total_employers',
                     'total_jobs', 'open_jobs', 'total_proposals',
                     'active_contracts', 'completed_contracts', 'total_revenue',
                     'recent_users', 'recent_jobs',
                 ],
             ]);
    }

    // ─── DASHBOARD ─────────────────────────────────────────────────────

    public function test_dashboard_returns_accurate_counts(): void
    {
        Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-'.Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-'.Str::random(4)]);

        $employer2 = User::create(['name' => 'Emp2', 'email' => 'emp2@test.com', 'password' => bcrypt('password'), 'role' => 'employer']);
        Job::create([
            'employer_id' => $employer2->id,
            'title' => 'Test Job',
            'slug' => 'test-job-'.Str::random(4),
            'description' => 'A test job.',
            'status' => 'open',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['total_users']);
        $this->assertGreaterThanOrEqual(0, $data['total_freelancers']);
        $this->assertGreaterThanOrEqual(0, $data['total_employers']);
        $this->assertArrayHasKey('recent_users', $data);
        $this->assertArrayHasKey('recent_jobs', $data);
    }

    // ─── USER MANAGEMENT ───────────────────────────────────────────────

    public function test_admin_can_list_users(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/users')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'success', 'message', 'data' => [
                     '*' => ['id', 'name', 'email', 'role', 'status'],
                 ],
                 'meta' => ['current_page', 'last_page', 'total'],
             ]);
    }

    public function test_admin_can_search_users(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/users?search=Employer');

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('email', 'employer@test.com'));
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/users?role=admin');

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertTrue($data->every('role', 'admin'));
    }

    public function test_admin_can_filter_users_by_status(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/users?status=active');

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertTrue($data->every('status', 'active'));
    }

    public function test_admin_can_view_single_user(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/admin/users/' . $this->employer->id)
             ->assertStatus(200)
             ->assertJsonPath('data.email', 'employer@test.com');
    }

    public function test_admin_can_deactivate_user(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/users/' . $this->employer->id . '/status', [
                             'is_active' => false,
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('users', ['id' => $this->employer->id, 'status' => 'suspended']);
    }

    public function test_admin_can_activate_user(): void
    {
        $this->employer->update(['status' => 'suspended']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/users/' . $this->employer->id . '/status', [
                             'is_active' => true,
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_change_user_role(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/users/' . $this->freelancer->id . '/role', [
                             'role' => 'employer',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.role', 'employer');

        $this->assertDatabaseHas('users', ['id' => $this->freelancer->id, 'role' => 'employer']);
    }

    public function test_admin_can_delete_user(): void
    {
        $userToDelete = User::create([
            'name' => 'Delete Me',
            'email' => 'deleteme@test.com',
            'password' => bcrypt('password'),
            'role' => 'freelancer',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->deleteJson('/api/v1/admin/users/' . $userToDelete->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $userToDelete->id]);
    }

    // ─── CATEGORIES ────────────────────────────────────────────────────

    public function test_admin_can_manage_categories(): void
    {
        // 1. Create
        $createRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                          ->postJson('/api/v1/admin/categories', [
                              'name' => 'DevOps & Cloud',
                              'description' => 'AWS, Docker, CI/CD',
                          ]);
        $createRes->assertStatus(201);
        $catId = $createRes->json('data.id');

        // 2. List
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                        ->getJson('/api/v1/admin/categories');
        $listRes->assertStatus(200);

        // 3. Update
        $updateRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                          ->putJson('/api/v1/admin/categories/' . $catId, [
                              'name' => 'DevOps & Cloud Systems',
                          ]);
        $updateRes->assertStatus(200)
                  ->assertJsonPath('data.name', 'DevOps & Cloud Systems');

        // 4. Delete
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->deleteJson('/api/v1/admin/categories/' . $catId);
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('categories', ['id' => $catId]);
    }

    // ─── JOBS ──────────────────────────────────────────────────────────

    public function test_admin_can_manage_jobs(): void
    {
        $job = Job::create([
            'employer_id' => $this->employer->id,
            'title' => 'Sample Admin Job',
            'slug' => 'sample-admin-job-'.Str::random(4),
            'description' => 'Test admin job description.',
            'status' => 'open',
        ]);

        // 1. List
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                        ->getJson('/api/v1/admin/jobs');
        $listRes->assertStatus(200);

        // 2. Moderate status
        $modRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->putJson('/api/v1/admin/jobs/' . $job->id . '/status', [
                           'status' => 'closed',
                       ]);
        $modRes->assertStatus(200);
        $this->assertEquals('closed', $job->fresh()->status);

        // 3. Delete
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->deleteJson('/api/v1/admin/jobs/' . $job->id);
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('marketplace_jobs', ['id' => $job->id]);
    }

    // ─── SKILLS ────────────────────────────────────────────────────────

    public function test_admin_can_manage_skills(): void
    {
        $category = Category::create(['name' => 'Design', 'slug' => 'design-'.Str::random(4)]);

        // 1. Create
        $createRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                          ->postJson('/api/v1/admin/skills', [
                              'name' => 'TailwindCSS',
                              'category_id' => $category->id,
                          ]);
        $createRes->assertStatus(201);
        $skillId = $createRes->json('data.id');

        // 2. List
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                        ->getJson('/api/v1/admin/skills');
        $listRes->assertStatus(200);

        // 3. Update
        $updateRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                          ->putJson('/api/v1/admin/skills/' . $skillId, [
                              'name' => 'Tailwind CSS v4',
                          ]);
        $updateRes->assertStatus(200);

        // 4. Delete
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->deleteJson('/api/v1/admin/skills/' . $skillId);
        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('skills', ['id' => $skillId]);
    }

    // ─── SETTINGS ──────────────────────────────────────────────────────

    public function test_admin_can_manage_settings(): void
    {
        $getRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->getJson('/api/v1/admin/settings');
        $getRes->assertStatus(200);

        $putRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->putJson('/api/v1/admin/settings', [
                           'platform_name' => 'Dream More AppWorks Updated',
                       ]);
        $putRes->assertStatus(200)
               ->assertJsonPath('data.general.platform_name', 'Dream More AppWorks Updated');
    }

    // ─── VERIFICATIONS ─────────────────────────────────────────────────

    public function test_admin_can_manage_verifications(): void
    {
        $verification = Verification::create([
            'user_id' => $this->freelancer->id,
            'type' => 'identity',
            'status' => 'pending',
        ]);

        // 1. List
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                        ->getJson('/api/v1/admin/verifications');
        $listRes->assertStatus(200);

        // 2. Show
        $showRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/verifications/' . $verification->id);
        $showRes->assertStatus(200);

        // 3. Approve
        $appRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->putJson('/api/v1/admin/verifications/' . $verification->id . '/approve');
        $appRes->assertStatus(200)
               ->assertJsonPath('data.status', 'approved');

        // 4. Reject another
        $verification2 = Verification::create([
            'user_id' => $this->employer->id,
            'type' => 'business',
            'status' => 'pending',
        ]);

        $rejRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->putJson('/api/v1/admin/verifications/' . $verification2->id . '/reject', [
                           'reason' => 'Invalid documents',
                       ]);
        $rejRes->assertStatus(200)
               ->assertJsonPath('data.status', 'rejected');
    }

    // ─── REPORTS ───────────────────────────────────────────────────────

    public function test_admin_can_manage_reports(): void
    {
        $report = Report::create([
            'reporter_id' => $this->freelancer->id,
            'target_type' => 'job',
            'target_id' => 1,
            'reason' => 'Spam posting',
            'status' => 'pending',
        ]);

        // 1. List
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                        ->getJson('/api/v1/admin/reports');
        $listRes->assertStatus(200);

        // 2. Show
        $showRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/reports/' . $report->id);
        $showRes->assertStatus(200);

        // 3. Resolve
        $resRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->putJson('/api/v1/admin/reports/' . $report->id . '/resolve', [
                           'resolution' => 'Removed job posting',
                       ]);
        $resRes->assertStatus(200)
               ->assertJsonPath('data.status', 'resolved');

        // 4. Dismiss another
        $report2 = Report::create([
            'reporter_id' => $this->employer->id,
            'target_type' => 'user',
            'target_id' => 2,
            'reason' => 'Inappropriate behavior',
            'status' => 'pending',
        ]);

        $disRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                       ->deleteJson('/api/v1/admin/reports/' . $report2->id);
        $disRes->assertStatus(200)
               ->assertJsonPath('data.status', 'dismissed');
    }

    // ─── SELF-PROTECTION ───────────────────────────────────────────────

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        // Cannot deactivate self
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/users/' . $this->admin->id . '/status', ['is_active' => false])
             ->assertStatus(422);

        // Cannot change own role to non-admin
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/users/' . $this->admin->id . '/role', ['role' => 'freelancer'])
             ->assertStatus(422);

        // Cannot delete self
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->deleteJson('/api/v1/admin/users/' . $this->admin->id)
             ->assertStatus(422);
    }
}
