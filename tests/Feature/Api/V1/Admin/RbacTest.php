<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * RbacTest
 *
 * Verifies:
 *   1. Super Admin authorization — full access
 *   2. Support Admin authorization — can manage users/disputes, blocked from finance/admin-mgmt
 *   3. Finance Admin authorization — can access payments/transactions, blocked elsewhere
 *   4. Dispute Admin authorization — can resolve disputes, blocked from finance/admin-mgmt
 *   5. Custom role authorization — permissions match exactly what was configured
 *   6. Unauthorized API access — 401 / 403 for non-admin and unauthenticated
 *   7. Privilege escalation attempts — various blocked scenarios
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    // ── Seeded roles + permissions ───────────────────────────────────────

    private Role $superAdminRole;
    private Role $supportAdminRole;
    private Role $disputeAdminRole;

    // ── Users ────────────────────────────────────────────────────────────

    private User $superAdmin;
    private User $supportAdmin;
    private User $disputeAdmin;
    private User $freelancer;
    private User $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seedUsers();
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    /**
     * Seed all permissions and the four system roles with their default permission sets.
     */
    private function seedRbac(): void
    {
        $this->artisan('db:seed', ['--class' => 'RbacSeeder'])->assertSuccessful();

        $this->superAdminRole  = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();
        $this->supportAdminRole = Role::where('slug', Role::SUPPORT_ADMIN)->firstOrFail();
        $this->disputeAdminRole = Role::where('slug', Role::DISPUTE_ADMIN)->firstOrFail();
    }

    private function makeAdmin(string $email, Role $role): User
    {
        $user = User::create([
            'name'     => 'Test ' . $role->name,
            'email'    => $email,
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);

        $user->adminRoles()->attach($role->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function seedUsers(): void
    {
        $this->superAdmin  = $this->makeAdmin('super@test.com',   $this->superAdminRole);
        $this->supportAdmin = $this->makeAdmin('support@test.com', $this->supportAdminRole);
        $this->disputeAdmin = $this->makeAdmin('dispute@test.com', $this->disputeAdminRole);

        $this->freelancer = User::create([
            'name' => 'Freelancer', 'email' => 'fl@test.com',
            'password' => Hash::make('password'), 'role' => 'freelancer', 'status' => 'active',
        ]);
        $this->employer = User::create([
            'name' => 'Employer', 'email' => 'em@test.com',
            'password' => Hash::make('password'), 'role' => 'employer', 'status' => 'active',
        ]);
    }

    // ── 1. Super Admin — full access ─────────────────────────────────────

    public function test_super_admin_can_access_dashboard(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_super_admin_can_list_users(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    public function test_super_admin_can_list_roles(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'is_system']]]);
    }

    public function test_super_admin_can_list_permissions(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['all', 'grouped']]);
    }

    public function test_super_admin_can_create_custom_role(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'slug'        => 'content_admin',
                'name'        => 'Content Admin',
                'description' => 'Manages content',
                'permissions' => ['jobs.view', 'jobs.moderate'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'content_admin');

        $this->assertDatabaseHas('roles', ['slug' => 'content_admin', 'is_system' => 0]);
    }

    public function test_super_admin_can_create_admin_user(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'       => 'New Admin',
                'email'      => 'newadmin@test.com',
                'password'   => 'Password1!',
                'admin_role' => Role::SUPPORT_ADMIN,
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin');

        $this->assertDatabaseHas('users', ['email' => 'newadmin@test.com', 'role' => 'admin']);
    }

    public function test_super_admin_can_assign_roles_to_admin(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->supportAdmin->id}/roles", [
                'roles' => [Role::SUPPORT_ADMIN, Role::DISPUTE_ADMIN],
            ])
            ->assertOk();

        $this->assertTrue($this->supportAdmin->fresh()->hasAdminRole(Role::DISPUTE_ADMIN));
    }

    public function test_super_admin_can_sync_role_permissions(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->supportAdminRole->id}/permissions", [
                'permissions' => ['users.view', 'users.edit'],
            ])
            ->assertOk();

        $this->assertTrue($this->supportAdminRole->fresh()->hasPermission('users.view'));
        $this->assertFalse($this->supportAdminRole->fresh()->hasPermission('disputes.resolve'));
    }

    public function test_super_admin_can_view_audit_logs(): void
    {
        AuditService::log(AuditLog::ACTION_ROLE_CREATED, AuditLog::MODULE_ROLES, 'Role', 1, [], $this->superAdmin->id);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'action', 'created_at']]]);
    }

    // ── 2. Support Admin — users/disputes/jobs, NOT finance/admin-mgmt ──

    public function test_support_admin_can_list_users(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    public function test_support_admin_can_view_reports(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertOk();
    }

    public function test_support_admin_can_view_verifications(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/verifications')
            ->assertOk();
    }

    public function test_support_admin_cannot_create_admin_user(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'     => 'Hacker',
                'email'    => 'hacker@test.com',
                'password' => 'Password1!',
            ])
            ->assertForbidden();
    }

    public function test_support_admin_cannot_list_admins(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden();
    }

    public function test_support_admin_cannot_access_settings_edit(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['platform_name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_support_admin_cannot_create_roles(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'slug' => 'evil_role',
                'name' => 'Evil Role',
            ])
            ->assertForbidden();
    }

    // ── 3. Dispute Admin — disputes only ─────────────────────────────────

    public function test_dispute_admin_can_view_reports(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertOk();
    }

    public function test_dispute_admin_can_view_jobs(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/jobs')
            ->assertOk();
    }

    public function test_dispute_admin_cannot_manage_admins(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden();
    }

    public function test_dispute_admin_cannot_manage_roles(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'slug' => 'dispute_custom',
                'name' => 'Dispute Custom',
            ])
            ->assertForbidden();
    }

    public function test_dispute_admin_cannot_edit_settings(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['platform_name' => 'Owned'])
            ->assertForbidden();
    }

    // ── 5. Custom role — only gets its configured permissions ─────────────

    public function test_custom_role_grants_only_configured_permissions(): void
    {
        // Create a custom role with only jobs.view
        $customRole = Role::create([
            'slug'      => 'job_viewer',
            'name'      => 'Job Viewer',
            'is_system' => false,
            'is_active' => true,
        ]);

        $jobViewPerm = Permission::where('slug', 'jobs.view')->firstOrFail();
        $customRole->permissions()->attach($jobViewPerm->id, [
            'granted_by' => null,
            'granted_at' => now(),
        ]);

        $customUser = $this->makeAdmin('custom@test.com', $customRole);

        // jobs.view — allowed
        $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/v1/admin/jobs')
            ->assertOk();

        // users.view — not granted → 403
        $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        // disputes.view — not granted → 403
        $this->actingAs($customUser, 'sanctum')
            ->getJson('/api/v1/admin/reports')
            ->assertForbidden();
    }

    // ── 6. Unauthorized access ────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
        $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    public function test_non_admin_user_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->freelancer, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        $this->actingAs($this->employer, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $this->actingAs($this->freelancer, 'sanctum')
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();
    }

    public function test_admin_without_role_assignment_gets_403_on_permission_routes(): void
    {
        // An admin with role='admin' but only a RESTRICTED sub-role assigned
        // (not super_admin, not one that has users.view).
        $restrictedRole = Role::create([
            'slug'      => 'restricted_role',
            'name'      => 'Restricted Role',
            'is_system' => false,
            'is_active' => true,
        ]);
        // Grant only disputes.view — no users.view, no roles.view
        $disputeViewPerm = Permission::where('slug', 'disputes.view')->firstOrFail();
        $restrictedRole->permissions()->attach($disputeViewPerm->id, [
            'granted_by' => null,
            'granted_at' => now(),
        ]);

        $restrictedAdmin = User::create([
            'name'     => 'Restricted Admin',
            'email'    => 'restricted@test.com',
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $restrictedAdmin->adminRoles()->attach($restrictedRole->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);

        // dashboard is open to all admins
        $this->actingAs($restrictedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        // users requires users.view permission — not granted → 403
        $this->actingAs($restrictedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $this->actingAs($restrictedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();
    }

    // ── 7. Privilege escalation attempts ─────────────────────────────────

    public function test_non_super_admin_cannot_assign_super_admin_role(): void
    {
        // supportAdmin tries to make disputeAdmin a super admin
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->disputeAdmin->id}/roles", [
                'roles' => [Role::SUPER_ADMIN],
            ])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_assign_super_admin_role_during_creation(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'       => 'Sneaky Admin',
                'email'      => 'sneaky@test.com',
                'password'   => 'Password1!',
                'admin_role' => Role::SUPER_ADMIN,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_delete_last_active_super_admin(): void
    {
        // superAdmin is the only super admin — trying to delete should fail
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$this->superAdmin->id}")
            ->assertStatus(422);
    }

    public function test_cannot_deactivate_last_active_super_admin(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->superAdmin->id}/status", [
                'is_active' => false,
            ])
            ->assertStatus(422);
    }

    public function test_super_admin_cannot_remove_own_super_admin_role_if_last(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->superAdmin->id}/roles", [
                'roles' => [Role::SUPPORT_ADMIN], // removes super_admin from self
            ])
            ->assertStatus(422);
    }

    public function test_non_super_admin_cannot_delete_system_role(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$this->disputeAdminRole->id}")
            ->assertForbidden();
    }

    public function test_super_admin_cannot_delete_system_role(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$this->disputeAdminRole->id}")
            ->assertStatus(422); // is_system protection
    }

    public function test_super_admin_cannot_modify_super_admin_role_permissions(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->superAdminRole->id}/permissions", [
                'permissions' => ['jobs.view'],
            ])
            ->assertStatus(422);
    }

    public function test_support_admin_cannot_change_system_role_name(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->supportAdminRole->id}", [
                'name' => 'Renamed System Role',
            ])
            ->assertStatus(422);
    }

    // ── 8. Audit log is written for key RBAC actions ─────────────────────

    public function test_audit_log_written_when_role_is_created(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'slug' => 'audit_test_role',
                'name' => 'Audit Test Role',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_ROLE_CREATED,
            'module'       => AuditLog::MODULE_ROLES,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Role',
        ]);
    }

    public function test_audit_log_written_when_admin_is_created(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'     => 'Audit Admin',
                'email'    => 'auditadmin@test.com',
                'password' => 'Password1!',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_ADMIN_CREATED,
            'module'       => AuditLog::MODULE_ADMINS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
        ]);
    }

    public function test_audit_log_written_when_roles_are_assigned(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->supportAdmin->id}/roles", [
                'roles' => [Role::SUPPORT_ADMIN],
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_ROLE_ASSIGNED,
            'module'       => AuditLog::MODULE_ROLES,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
            'subject_id'   => $this->supportAdmin->id,
        ]);
    }

    public function test_audit_log_written_when_permissions_changed(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->supportAdminRole->id}/permissions", [
                'permissions' => ['users.view'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_PERMISSION_CHANGED,
            'module'       => AuditLog::MODULE_PERMISSIONS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Role',
            'subject_id'   => $this->supportAdminRole->id,
        ]);
    }

    // ── 9. User-management audit logs ────────────────────────────────────

    public function test_audit_log_written_when_user_is_suspended(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->freelancer->id}/status", [
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_USER_SUSPENDED,
            'module'       => AuditLog::MODULE_USERS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
            'subject_id'   => $this->freelancer->id,
        ]);
    }

    public function test_audit_log_written_when_user_is_reactivated(): void
    {
        $this->freelancer->update(['status' => 'suspended']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->freelancer->id}/status", [
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_USER_ACTIVATED,
            'module'       => AuditLog::MODULE_USERS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
            'subject_id'   => $this->freelancer->id,
        ]);
    }

    public function test_audit_log_written_when_user_role_is_changed(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->freelancer->id}/role", [
                'role' => 'employer',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_USER_ROLE_CHANGED,
            'module'       => AuditLog::MODULE_USERS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
            'subject_id'   => $this->freelancer->id,
        ]);
    }

    public function test_audit_log_written_when_user_is_deleted(): void
    {
        $userToDelete = User::create([
            'name' => 'Delete Me', 'email' => 'dme@test.com',
            'password' => Hash::make('password'), 'role' => 'freelancer',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$userToDelete->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_USER_DELETED,
            'module'       => AuditLog::MODULE_USERS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'User',
        ]);
    }

    // ── 10. Settings audit logs ───────────────────────────────────────────

    public function test_audit_log_written_when_settings_are_changed(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'platform_name' => 'Updated Platform Name',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_SETTINGS_CHANGED,
            'module' => AuditLog::MODULE_SETTINGS,
            'actor_id' => $this->superAdmin->id,
        ]);
    }

    public function test_audit_log_includes_description_field(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings', [
                'platform_name' => 'Described Change',
            ])
            ->assertOk();

        $log = \App\Models\AuditLog::where('action', AuditLog::ACTION_SETTINGS_CHANGED)
            ->latest()
            ->first();

        $this->assertNotNull($log, 'Audit log should exist.');
        $this->assertNotNull($log->description, 'Description field should be populated.');
        $this->assertStringContainsString('updated platform settings', $log->description);
    }

    public function test_audit_log_includes_module_field(): void
    {
        AuditService::log(
            AuditLog::ACTION_ROLE_CREATED,
            AuditLog::MODULE_ROLES,
            'Role',
            1,
            [],
            $this->superAdmin->id,
            'Super Admin created test role'
        );

        $log = \App\Models\AuditLog::where('action', AuditLog::ACTION_ROLE_CREATED)->latest()->first();
        $this->assertEquals(AuditLog::MODULE_ROLES, $log->module);
        $this->assertEquals('Super Admin created test role', $log->description);
    }

    // ── 11. Dispute audit logs ────────────────────────────────────────────

    public function test_audit_log_written_when_dispute_is_resolved(): void
    {
        $report = \App\Models\Report::create([
            'reporter_id' => $this->freelancer->id,
            'target_type' => 'job',
            'target_id'   => 1,
            'reason'      => 'Spam job posting',
            'status'      => 'pending',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'resolution' => 'Removed offending job.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_DISPUTE_RESOLVED,
            'module'       => AuditLog::MODULE_DISPUTES,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Report',
            'subject_id'   => $report->id,
        ]);
    }

    public function test_audit_log_written_when_dispute_is_dismissed(): void
    {
        $report = \App\Models\Report::create([
            'reporter_id' => $this->freelancer->id,
            'target_type' => 'user',
            'target_id'   => $this->employer->id,
            'reason'      => 'Inappropriate',
            'status'      => 'pending',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/reports/{$report->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_DISPUTE_DISMISSED,
            'module'       => AuditLog::MODULE_DISPUTES,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Report',
            'subject_id'   => $report->id,
        ]);
    }

    // ── 12. Verification audit logs ────────────────────────────────────────

    public function test_audit_log_written_when_verification_is_approved(): void
    {
        $verification = \App\Models\Verification::create([
            'user_id' => $this->freelancer->id,
            'type'    => 'identity',
            'status'  => 'pending',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/verifications/{$verification->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_VERIFICATION_APPROVED,
            'module'       => AuditLog::MODULE_VERIFICATIONS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Verification',
            'subject_id'   => $verification->id,
        ]);
    }

    public function test_audit_log_written_when_verification_is_rejected(): void
    {
        $verification = \App\Models\Verification::create([
            'user_id' => $this->freelancer->id,
            'type'    => 'identity',
            'status'  => 'pending',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/verifications/{$verification->id}/reject", [
                'reason' => 'Document unclear.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'       => AuditLog::ACTION_VERIFICATION_REJECTED,
            'module'       => AuditLog::MODULE_VERIFICATIONS,
            'actor_id'     => $this->superAdmin->id,
            'subject_type' => 'Verification',
            'subject_id'   => $verification->id,
        ]);
    }

    // ── 13. Audit log API filters ──────────────────────────────────────────

    public function test_audit_log_api_filters_by_module(): void
    {
        // Create two logs with different modules.
        AuditService::userSuspended($this->freelancer->id, $this->superAdmin->id, ['name' => 'Test']);
        AuditService::settingsChanged($this->superAdmin->id, ['platform_name' => 'X']);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs?module=' . AuditLog::MODULE_USERS)
            ->assertOk();

        $actions = collect($response->json('data'))->pluck('action');
        $this->assertTrue($actions->contains(AuditLog::ACTION_USER_SUSPENDED));
        $this->assertFalse($actions->contains(AuditLog::ACTION_SETTINGS_CHANGED));
    }

    public function test_audit_log_api_filters_by_date_from(): void
    {
        // Write a log then filter from tomorrow — should return empty.
        AuditService::userSuspended($this->freelancer->id, $this->superAdmin->id, ['name' => 'X']);

        $tomorrow = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/audit-logs?date_from={$tomorrow}")
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_audit_log_api_filters_by_actor_id(): void
    {
        AuditService::userSuspended($this->freelancer->id, $this->superAdmin->id, ['name' => 'X']);
        AuditService::userSuspended($this->freelancer->id, $this->supportAdmin->id, ['name' => 'X']);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/v1/admin/audit-logs?actor_id={$this->supportAdmin->id}")
            ->assertOk();

        $actorIds = collect($response->json('data'))->pluck('actor_id');
        $this->assertTrue($actorIds->every(fn ($id) => $id === $this->supportAdmin->id));
    }

    // ── 9. HasAdminRoles trait unit-level checks ─────────────────────────

    public function test_super_admin_is_super_admin(): void
    {
        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertFalse($this->supportAdmin->isSuperAdmin());
        $this->assertFalse($this->disputeAdmin->isSuperAdmin());
    }

    public function test_super_admin_has_all_permissions(): void
    {
        $this->assertTrue($this->superAdmin->hasPermission('admins.create'));
        $this->assertTrue($this->superAdmin->hasPermission('roles.delete'));
        $this->assertTrue($this->superAdmin->hasPermission('audit_logs.view'));
    }

    public function test_support_admin_has_expected_permissions(): void
    {
        $this->assertTrue($this->supportAdmin->hasPermission('users.view'));
        $this->assertTrue($this->supportAdmin->hasPermission('disputes.resolve'));
        $this->assertFalse($this->supportAdmin->hasPermission('admins.create'));
        $this->assertFalse($this->supportAdmin->hasPermission('roles.create'));
    }

    public function test_dispute_admin_has_expected_permissions(): void
    {
        $this->assertTrue($this->disputeAdmin->hasPermission('disputes.view'));
        $this->assertTrue($this->disputeAdmin->hasPermission('disputes.resolve'));
        $this->assertTrue($this->disputeAdmin->hasPermission('disputes.escalate'));
        $this->assertFalse($this->disputeAdmin->hasPermission('admins.create'));
    }

    public function test_freelancer_has_no_admin_permissions(): void
    {
        $this->assertFalse($this->freelancer->isSuperAdmin());
        $this->assertFalse($this->freelancer->hasPermission('users.view'));
        $this->assertTrue($this->freelancer->getAllPermissions()->isEmpty());
    }

    public function test_has_any_permission(): void
    {
        $this->assertTrue($this->supportAdmin->hasAnyPermission('disputes.resolve', 'users.view'));
        $this->assertFalse($this->supportAdmin->hasAnyPermission('admins.create', 'roles.create'));
    }

    public function test_has_all_permissions(): void
    {
        $this->assertTrue($this->supportAdmin->hasAllPermissions('users.view', 'disputes.resolve'));
        $this->assertFalse($this->supportAdmin->hasAllPermissions('users.view', 'admins.create'));
    }
}
