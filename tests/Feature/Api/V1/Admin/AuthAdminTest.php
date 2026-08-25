<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AuthAdminTest
 *
 * Tests authentication flows specifically for admin users:
 *   1. Valid admin login issues a Sanctum token
 *   2. Invalid credentials return 422
 *   3. Deactivated (suspended) admin cannot log in
 *   4. Wrong password returns 422
 *   5. Non-existent email returns 422
 *   6. Logged-in admin can access GET /auth/me
 *   7. Logged-in admin can log out and token is revoked
 *   8. Admin with no sub-roles is still authenticated (bootstrap mode)
 *   9. Admin with sub-role gets correct role info in /auth/me
 *  10. Token from a suspended admin is rejected on subsequent requests
 *     (i.e. after being suspended, existing token still authenticates via
 *      Sanctum, but status check in admin routes is enforced where applicable)
 */
class AuthAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'AdminPass1!';

    private User $activeAdmin;
    private User $suspendedAdmin;
    private User $adminWithRole;
    private Role $supportRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'RbacSeeder'])->assertSuccessful();

        $this->supportRole = Role::where('slug', Role::SUPPORT_ADMIN)->firstOrFail();

        // Plain active admin — no sub-role (bootstrap Super Admin mode).
        $this->activeAdmin = User::create([
            'name'     => 'Active Admin',
            'email'    => 'active.admin@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'admin',
            'status'   => 'active',
        ]);

        // Suspended admin.
        $this->suspendedAdmin = User::create([
            'name'     => 'Suspended Admin',
            'email'    => 'suspended.admin@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'admin',
            'status'   => 'suspended',
        ]);

        // Admin with Support Admin sub-role.
        $this->adminWithRole = User::create([
            'name'     => 'Role Admin',
            'email'    => 'role.admin@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $this->adminWithRole->adminRoles()->attach($this->supportRole->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);
    }

    // ── 1. Valid admin login ──────────────────────────────────────────────

    public function test_active_admin_can_login_with_correct_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->activeAdmin->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User authenticated successfully.')
            ->assertJsonStructure([
                'data' => [
                    'user'  => ['id', 'email', 'role', 'status'],
                    'token',
                ],
            ]);

        // Confirm role = admin in the response.
        $this->assertEquals('admin', $response->json('data.user.role'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_response_includes_last_login_at(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->activeAdmin->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        // last_login_at should now be set in the DB.
        $this->assertNotNull($this->activeAdmin->fresh()->last_login_at);
    }

    // ── 2. Invalid credentials ─────────────────────────────────────────────

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->activeAdmin->email,
            'password' => 'WrongPassword!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'nobody@test.com',
            'password' => self::PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_with_empty_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_fails_with_invalid_email_format(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'not-an-email',
            'password' => self::PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
    }

    // ── 3. Deactivated admin cannot log in ────────────────────────────────

    public function test_suspended_admin_cannot_log_in(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => $this->suspendedAdmin->email,
            'password' => self::PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account']);
    }

    public function test_suspended_admin_error_message_mentions_status(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->suspendedAdmin->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.account');
        $this->assertNotEmpty($errors);
        // Error message should mention the suspended status.
        $this->assertStringContainsString('suspended', strtolower($errors[0]));
    }

    public function test_pending_verification_admin_cannot_log_in(): void
    {
        $pendingAdmin = User::create([
            'name'     => 'Pending Admin',
            'email'    => 'pending.admin@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'admin',
            'status'   => 'pending_verification',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $pendingAdmin->email,
            'password' => self::PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account']);
    }

    // ── 4. Authenticated admin endpoints ─────────────────────────────────

    public function test_authenticated_admin_can_fetch_own_profile(): void
    {
        $token = $this->activeAdmin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->activeAdmin->email)
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_authenticated_admin_can_access_admin_dashboard(): void
    {
        // activeAdmin has no sub-role → bootstrap Super Admin → can access dashboard.
        $token = $this->activeAdmin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_authenticated_admin_can_logout(): void
    {
        // Use real token flow: login → get token → logout → verify revoked.
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->activeAdmin->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $token = $loginRes->json('data.token');
        $this->assertNotEmpty($token);

        // Extract the token ID (before the "|" pipe character).
        $tokenId = explode('|', $token)[0];

        // Verify token exists in DB before logout.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        // Logout.
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'User logged out successfully.');

        // Token must be deleted from the DB — no longer valid.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ── 5. Admin with sub-role login ──────────────────────────────────────

    public function test_admin_with_support_role_logs_in_successfully(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $this->adminWithRole->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_admin_with_support_role_can_access_permitted_endpoints(): void
    {
        $token = $this->adminWithRole->createToken('test')->plainTextToken;

        // Support Admin has users.view → can access /admin/users.
        $this->withToken($token)
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    public function test_admin_with_support_role_cannot_access_roles_endpoint(): void
    {
        $token = $this->adminWithRole->createToken('test')->plainTextToken;

        // Support Admin does NOT have roles.view.
        $this->withToken($token)
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();
    }

    // ── 6. Token security ─────────────────────────────────────────────────

    public function test_invalid_bearer_token_returns_401(): void
    {
        $this->withToken('totally-fake-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_expired_or_revoked_token_returns_401(): void
    {
        $token = $this->activeAdmin->createToken('revoke_me')->plainTextToken;

        // Revoke manually.
        $this->activeAdmin->tokens()->delete();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_multiple_login_sessions_each_get_own_token(): void
    {
        $r1 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->activeAdmin->email, 'password' => self::PASSWORD,
        ])->assertOk();

        $r2 = $this->postJson('/api/v1/auth/login', [
            'email' => $this->activeAdmin->email, 'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotEquals(
            $r1->json('data.token'),
            $r2->json('data.token'),
            'Each login should produce a unique token.'
        );
    }

    // ── 7. Admin status changes affect subsequent API access ─────────────

    public function test_admin_suspended_after_login_cannot_log_in_again(): void
    {
        // First login succeeds.
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->activeAdmin->email, 'password' => self::PASSWORD,
        ])->assertOk();

        // Admin is then suspended.
        $this->activeAdmin->update(['status' => 'suspended']);

        // Second login attempt fails.
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->activeAdmin->email, 'password' => self::PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account']);
    }

    // ── 8. Non-admin users cannot access admin area ───────────────────────

    public function test_freelancer_with_valid_token_cannot_access_admin_dashboard(): void
    {
        $freelancer = User::create([
            'name'     => 'FL',
            'email'    => 'fl2@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $token = $freelancer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_employer_with_valid_token_cannot_access_admin_routes(): void
    {
        $employer = User::create([
            'name'     => 'EM',
            'email'    => 'em2@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $token = $employer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }
}
