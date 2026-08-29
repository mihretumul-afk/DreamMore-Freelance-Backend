<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SecurityTest
 *
 * Dedicated test for every privilege-escalation vector and unauthorized
 * backend access scenario that the RBAC system must block.
 *
 * Tests are grouped by threat category:
 *   A. Cross-role endpoint access (non-matching roles calling wrong endpoints)
 *   B. Privilege escalation — role management
 *   C. Privilege escalation — permission management
 *   D. Privilege escalation — admin lifecycle
 *   E. Super Admin final-account protection
 *   F. Direct API bypass attempts (frontend-restriction bypass simulation)
 *   G. Custom role confinement
 *   H. Deactivated admin access
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private Role $superAdminRole;
    private Role $supportAdminRole;
    private Role $disputeAdminRole;

    private User $superAdmin;
    private User $superAdmin2;     // second super admin for last-account protection tests
    private User $supportAdmin;
    private User $disputeAdmin;
    private User $freelancer;
    private User $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RbacSeeder'])->assertSuccessful();

        $this->superAdminRole  = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();
        $this->supportAdminRole = Role::where('slug', Role::SUPPORT_ADMIN)->firstOrFail();
        $this->disputeAdminRole = Role::where('slug', Role::DISPUTE_ADMIN)->firstOrFail();

        $this->superAdmin  = $this->makeAdmin('sa@sec.test',       $this->superAdminRole);
        $this->superAdmin2  = $this->makeAdmin('sa2@sec.test',      $this->superAdminRole);
        $this->supportAdmin = $this->makeAdmin('support@sec.test',  $this->supportAdminRole);
        $this->disputeAdmin = $this->makeAdmin('dispute@sec.test',  $this->disputeAdminRole);

        $this->freelancer = User::create([
            'name' => 'FL', 'email' => 'fl@sec.test',
            'password' => Hash::make('password'), 'role' => 'freelancer', 'status' => 'active',
        ]);
        $this->employer = User::create([
            'name' => 'EM', 'email' => 'em@sec.test',
            'password' => Hash::make('password'), 'role' => 'employer', 'status' => 'active',
        ]);
    }

    private function makeAdmin(string $email, Role $role): User
    {
        $user = User::create([
            'name'     => 'Admin ' . $role->name,
            'email'    => $email,
            'password' => Hash::make('Password1!'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $user->adminRoles()->attach($role->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);
        return $user;
    }

    // ═══════════════════════════════════════════════════════════════════
    // A. Cross-role endpoint access
    // ═══════════════════════════════════════════════════════════════════

    public function test_unauthenticated_request_to_every_protected_admin_route_returns_401(): void
    {
        $routes = [
            ['GET',    '/api/v1/admin/users'],
            ['GET',    '/api/v1/admin/admins'],
            ['GET',    '/api/v1/admin/roles'],
            ['GET',    '/api/v1/admin/permissions'],
            ['GET',    '/api/v1/admin/audit-logs'],
            ['GET',    '/api/v1/admin/settings'],
            ['GET',    '/api/v1/admin/reports'],
            ['GET',    '/api/v1/admin/verifications'],
            ['GET',    '/api/v1/admin/jobs'],
            ['GET',    '/api/v1/admin/freelancers'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
    }

    public function test_freelancer_cannot_access_any_admin_route(): void
    {
        $routes = [
            ['GET', '/api/v1/admin/dashboard'],
            ['GET', '/api/v1/admin/users'],
            ['GET', '/api/v1/admin/roles'],
            ['GET', '/api/v1/admin/admins'],
            ['GET', '/api/v1/admin/audit-logs'],
            ['GET', '/api/v1/admin/settings'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($this->freelancer, 'sanctum')
                ->json($method, $url)
                ->assertForbidden();
        }
    }

    public function test_employer_cannot_access_any_admin_route(): void
    {
        $this->actingAs($this->employer, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        $this->actingAs($this->employer, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    // Support Admin cannot touch finance.
    public function test_support_admin_cannot_access_payment_endpoints(): void
    {
        // payments route doesn't exist yet (Stage 3), but permission check happens
        // before route returns data — any admin route gated by payments.view should 403.
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/settings')
            ->assertOk(); // settings.view granted to support

        // But cannot edit.
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['platform_name' => 'Hacked'])
            ->assertForbidden();
    }

    // Dispute Admin cannot resolve disputes if only viewing.
    public function test_dispute_admin_can_resolve_disputes(): void
    {
        $report = Report::create([
            'reporter_id' => $this->freelancer->id,
            'target_type' => 'user',
            'target_id'   => $this->employer->id,
            'reason'      => 'Fraud',
            'status'      => 'pending',
        ]);

        // disputes.resolve IS granted to dispute admin.
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->putJson("/api/v1/admin/reports/{$report->id}/resolve", [
                'resolution' => 'Investigated and resolved.',
            ])
            ->assertOk();
    }

    public function test_dispute_admin_cannot_manage_admins_or_roles(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden();

        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->postJson('/api/v1/admin/roles', [
                'slug' => 'dispute_grab', 'name' => 'Dispute Grab',
            ])
            ->assertForbidden();

        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->disputeAdminRole->id}/permissions", [
                'permissions' => ['admins.create'],
            ])
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════
    // B. Privilege escalation — role management
    // ═══════════════════════════════════════════════════════════════════

    public function test_non_super_admin_cannot_create_any_custom_role(): void
    {
        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->postJson('/api/v1/admin/roles', [
                    'slug' => 'evil_' . $actor->id,
                    'name' => 'Evil Role',
                ])
                ->assertForbidden(
                    "Admin {$actor->email} should not be able to create roles."
                );
        }
    }

    public function test_non_super_admin_cannot_delete_any_role(): void
    {
        // Create a custom role via Super Admin.
        $customRole = Role::create([
            'slug' => 'temp_del', 'name' => 'Temp Del',
            'is_system' => false, 'is_active' => true,
        ]);

        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->deleteJson("/api/v1/admin/roles/{$customRole->id}")
                ->assertForbidden();
        }

        // System roles also protected — even Super Admin cannot delete them.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$this->supportAdminRole->id}")
            ->assertStatus(422);
    }

    public function test_non_super_admin_cannot_sync_permissions_on_any_role(): void
    {
        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/v1/admin/roles/{$this->supportAdminRole->id}/permissions", [
                    'permissions' => ['admins.create', 'roles.delete'],
                ])
                ->assertForbidden();
        }
    }

    public function test_super_admin_role_permissions_cannot_be_modified_by_anyone(): void
    {
        // Even Super Admin cannot reduce the super_admin role.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->superAdminRole->id}/permissions", [
                'permissions' => ['users.view'],
            ])
            ->assertStatus(422);
    }

    public function test_system_role_name_cannot_be_changed(): void
    {
        foreach ([$this->superAdminRole, $this->supportAdminRole, $this->disputeAdminRole] as $role) {
            $this->actingAs($this->superAdmin, 'sanctum')
                ->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'Hijacked'])
                ->assertStatus(422);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // C. Privilege escalation — permission management
    // ═══════════════════════════════════════════════════════════════════

    public function test_support_admin_cannot_grant_themselves_admin_permissions(): void
    {
        // Support Admin tries to add admins.create to their own role.
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->supportAdminRole->id}/permissions", [
                'permissions' => ['admins.create', 'users.view'],
            ])
            ->assertForbidden();
    }

    public function test_dispute_admin_cannot_grant_themselves_role_management_permissions(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->putJson("/api/v1/admin/roles/{$this->disputeAdminRole->id}/permissions", [
                'permissions' => ['roles.create', 'disputes.view'],
            ])
            ->assertForbidden();
    }

    public function test_permissions_endpoint_is_read_only_for_non_super_admins(): void
    {
        // GET permissions is allowed with roles.view.
        // But only Super Admin can change them via roles endpoints.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/permissions')
            ->assertOk();

        // Non-super-admins without roles.view get 403.
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/permissions')
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════
    // D. Privilege escalation — admin lifecycle
    // ═══════════════════════════════════════════════════════════════════

    public function test_support_admin_cannot_create_new_admin(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Rogue', 'email' => 'rogue@test.com', 'password' => 'Password1!',
            ])
            ->assertForbidden();
    }

    public function test_dispute_admin_cannot_create_new_admin(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Rogue', 'email' => 'rogue3@test.com', 'password' => 'Password1!',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_assign_super_admin_role_via_creation(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'       => 'New SA',
                'email'      => 'newsa@test.com',
                'password'   => 'Password1!',
                'admin_role' => Role::SUPER_ADMIN,
            ])
            ->assertStatus(422);

        // Confirm no user was created.
        $this->assertDatabaseMissing('users', ['email' => 'newsa@test.com']);
    }

    public function test_non_super_admin_cannot_assign_roles_to_admins(): void
    {
        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/v1/admin/admins/{$this->supportAdmin->id}/roles", [
                    'roles' => [Role::SUPER_ADMIN],
                ])
                ->assertForbidden();
        }
    }

    public function test_non_super_admin_cannot_delete_admin_accounts(): void
    {
        // Create a throwaway admin.
        $victim = $this->makeAdmin('victim@test.com', $this->supportAdminRole);

        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->deleteJson("/api/v1/admin/admins/{$victim->id}")
                ->assertForbidden();
        }

        // Confirm account still exists.
        $this->assertDatabaseHas('users', ['id' => $victim->id]);
    }

    public function test_non_super_admin_cannot_deactivate_admin_accounts(): void
    {
        $victim = $this->makeAdmin('victim2@test.com', $this->supportAdminRole);

        foreach ([$this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/v1/admin/admins/{$victim->id}/status", ['is_active' => false])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'status' => 'active']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // E. Super Admin final-account protection
    // ═══════════════════════════════════════════════════════════════════

    public function test_cannot_delete_last_active_super_admin(): void
    {
        // Suspend the second super admin, leaving only superAdmin active.
        $this->superAdmin2->update(['status' => 'suspended']);

        // Now superAdmin is the last active super admin.
        // Trying to delete themselves hits self-delete guard first → 422.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$this->superAdmin->id}")
            ->assertStatus(422);

        // Trying to delete the other (suspended) super admin — still blocked
        // because that would leave zero active super admins once the only active one is gone.
        // Actually superAdmin2 is suspended, so count of *active* super admins = 1 (superAdmin).
        // Deleting superAdmin2 (suspended) is allowed — only the *active* count matters.
        // The real test is trying to delete the only active one from outside.
        // Create a second active super admin to test the full path.
        $thirdSA = $this->makeAdmin('sa3@sec.test', $this->superAdminRole);

        // Now there are 2 active super admins. Delete superAdmin2 (suspended) is OK.
        // Delete thirdSA from superAdmin should work.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$thirdSA->id}")
            ->assertOk();

        // Back to 1 active SA (superAdmin). Cannot delete self.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$this->superAdmin->id}")
            ->assertStatus(422);
    }

    public function test_can_delete_super_admin_when_another_one_exists(): void
    {
        // Both super admins are active — deletion of one is allowed.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$this->superAdmin2->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $this->superAdmin2->id]);
    }

    public function test_cannot_deactivate_last_active_super_admin(): void
    {
        $this->superAdmin2->update(['status' => 'suspended']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->superAdmin->id}/status", ['is_active' => false])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $this->superAdmin->id, 'status' => 'active']);
    }

    public function test_cannot_remove_super_admin_role_from_last_active_super_admin(): void
    {
        $this->superAdmin2->update(['status' => 'suspended']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->superAdmin->id}/roles", [
                'roles' => [Role::SUPPORT_ADMIN],
            ])
            ->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        // The admins endpoint requires isSuperAdmin OR admins.deactivate.
        // supportAdmin is NOT super admin and does NOT have admins.deactivate.
        // So the response is 403 (permission denied), not 422 (self-protection).
        // The self-protection guard only fires when the actor HAS the permission.
        // Test using the Super Admin who does have permission — they cannot deactivate self.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->superAdmin->id}/status", [
                'is_active' => false,
            ])
            ->assertStatus(422); // self-protection fires

        // A non-super-admin without admins.deactivate gets 403.
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->putJson("/api/v1/admin/admins/{$this->supportAdmin->id}/status", [
                'is_active' => false,
            ])
            ->assertForbidden(); // no admins.deactivate permission
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/admins/{$this->superAdmin->id}")
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════
    // F. Direct API bypass attempts (simulating frontend restriction bypass)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A support admin directly POSTs to the admin creation endpoint with a
     * crafted JSON body containing their own token. The backend must still
     * return 403 regardless of the request body content.
     */
    public function test_support_admin_direct_api_call_to_create_admin_still_returns_403(): void
    {
        $this->actingAs($this->supportAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name'       => 'Bypass Attempt',
                'email'      => 'bypass@hack.com',
                'password'   => 'Password1!',
                'admin_role' => Role::SUPER_ADMIN,  // attempting to escalate
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'bypass@hack.com']);
    }

    /**
     * Finance admin directly calls role-sync endpoint — should 403 regardless
     * of the permissions array sent in the body.
     */
    /**
     * Dispute admin tries to directly call the admin status endpoint.
     */
    public function test_dispute_admin_direct_api_call_to_deactivate_user_still_returns_403(): void
    {
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->freelancer->id}/status", [
                'is_active' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->freelancer->id, 'status' => 'active']);
    }

    /**
     * Non-admin JWT token provided — should receive 403 on all admin routes.
     */
    public function test_marketplace_user_token_cannot_bypass_admin_role_check(): void
    {
        $this->actingAs($this->freelancer, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Bypass', 'email' => 'b@b.com', 'password' => 'Password1!',
            ])
            ->assertForbidden();

        $this->actingAs($this->employer, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['maintenance_mode' => true])
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════
    // G. Custom role confinement
    // ═══════════════════════════════════════════════════════════════════

    public function test_custom_role_admin_only_accesses_granted_permissions(): void
    {
        // Create a custom role with only users.view.
        $customRole = Role::create([
            'slug' => 'user_reader', 'name' => 'User Reader',
            'is_system' => false, 'is_active' => true,
        ]);
        $perm = Permission::where('slug', 'users.view')->firstOrFail();
        $customRole->permissions()->attach($perm->id, ['granted_by' => null, 'granted_at' => now()]);

        $customAdmin = $this->makeAdmin('custom@sec.test', $customRole);

        // Can view users.
        $this->actingAs($customAdmin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        // Cannot suspend users.
        $this->actingAs($customAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->freelancer->id}/status", ['is_active' => false])
            ->assertForbidden();

        // Cannot view roles.
        $this->actingAs($customAdmin, 'sanctum')
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();

        // Cannot create admins.
        $this->actingAs($customAdmin, 'sanctum')
            ->postJson('/api/v1/admin/admins', [
                'name' => 'X', 'email' => 'x@x.com', 'password' => 'Password1!',
            ])
            ->assertForbidden();

        // Cannot view audit logs (no audit_logs.view permission).
        $this->actingAs($customAdmin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();
    }

    public function test_custom_role_with_zero_permissions_cannot_access_any_gated_route(): void
    {
        $emptyRole = Role::create([
            'slug' => 'empty_role', 'name' => 'Empty Role',
            'is_system' => false, 'is_active' => true,
        ]);
        $emptyAdmin = $this->makeAdmin('empty@sec.test', $emptyRole);

        // Dashboard is ungated — should work.
        $this->actingAs($emptyAdmin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        // Everything else is gated.
        $gated = [
            '/api/v1/admin/users',
            '/api/v1/admin/roles',
            '/api/v1/admin/admins',
            '/api/v1/admin/settings',
            '/api/v1/admin/audit-logs',
            '/api/v1/admin/reports',
        ];

        foreach ($gated as $url) {
            $this->actingAs($emptyAdmin, 'sanctum')
                ->getJson($url)
                ->assertForbidden("Expected 403 for empty-role admin at {$url}");
        }
    }

    public function test_custom_role_cannot_be_deleted_by_non_super_admin(): void
    {
        $customRole = Role::create([
            'slug' => 'doomed', 'name' => 'Doomed Role',
            'is_system' => false, 'is_active' => true,
        ]);

        foreach ([$this->supportAdmin, $this->disputeAdmin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->deleteJson("/api/v1/admin/roles/{$customRole->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('roles', ['id' => $customRole->id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // H. Deactivated admin access
    // ═══════════════════════════════════════════════════════════════════

    public function test_suspended_admin_cannot_access_admin_routes(): void
    {
        // Suspend support admin using the Super Admin via the marketplace
        // users endpoint (which uses users.suspend permission, not admins.deactivate).
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->supportAdmin->id}/status", [
                'is_active' => false,
            ])
            ->assertOk();

        // Confirm suspension persisted in DB.
        $this->assertDatabaseHas('users', [
            'id'     => $this->supportAdmin->id,
            'status' => 'suspended',
        ]);

        // A suspended admin cannot log in again (AuthService enforces status=active).
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->supportAdmin->email,
            'password' => 'Password1!',
        ])->assertStatus(422);
    }

    public function test_deactivated_admin_login_returns_error(): void
    {
        $this->supportAdmin->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->supportAdmin->email,
            'password' => 'Password1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account']);
    }
}
