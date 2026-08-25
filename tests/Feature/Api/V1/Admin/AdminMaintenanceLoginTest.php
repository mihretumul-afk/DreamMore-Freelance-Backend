<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaintenanceLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Super Admin',
            'email'    => 'admin@dreammore.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
    }

    /**
     * End-to-end flow:
     * 1. Turn maintenance mode ON
     * 2. Admin can login
     * 3. Admin can access admin dashboard
     * 4. Admin can access admin settings
     * 5. Admin can turn maintenance mode OFF
     * 6. Platform works normally again
     */
    public function test_full_admin_maintenance_flow(): void
    {
        // Step 1: Turn maintenance mode ON
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');
        $this->assertTrue(AdminSetting::getValue('maintenance_mode', 'false', 'boolean'));

        // Step 2: Public routes are blocked
        $this->getJson('/api/v1/categories')
             ->assertStatus(503)
             ->assertJson(['maintenance' => true]);

        // Step 3: Admin can login
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@dreammore.com',
            'password' => 'password',
        ]);
        $loginRes->assertStatus(200)
                 ->assertJson(['success' => true]);

        $token = $loginRes->json('data.token');
        $this->assertNotEmpty($token);

        // Step 4: Admin can access admin dashboard
        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/v1/admin/dashboard')
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        // Step 5: Admin can access admin settings
        $settingsRes = $this->withHeader('Authorization', 'Bearer ' . $token)
                            ->getJson('/api/v1/admin/settings');
        $settingsRes->assertStatus(200)
                    ->assertJson(['success' => true]);

        // Step 6: Admin can access categories (bypasses maintenance)
        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/v1/categories')
             ->assertStatus(200);

        // Step 7: Admin can turn maintenance mode OFF
        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->putJson('/api/v1/admin/settings', [
                 'maintenance_mode' => 'false',
             ])
             ->assertStatus(200);

        // Step 8: Verify maintenance mode is now OFF
        $this->assertFalse(AdminSetting::getValue('maintenance_mode', 'false', 'boolean'));

        // Step 9: Platform works normally again for public users
        $this->getJson('/api/v1/categories')
             ->assertStatus(200);
    }

    /**
     * Verify that non-admin users cannot bypass maintenance mode
     * even with a valid token.
     */
    public function test_non_admin_users_blocked_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        // Create a freelancer
        $freelancer = User::create([
            'name'     => 'Freelancer',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $freelancerToken = $freelancer->createToken('freelancer_token')->plainTextToken;

        // Create an employer
        $employer = User::create([
            'name'     => 'Employer',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $employerToken = $employer->createToken('employer_token')->plainTextToken;

        // Freelancer cannot access public routes
        $this->withHeader('Authorization', 'Bearer ' . $freelancerToken)
             ->getJson('/api/v1/categories')
             ->assertStatus(503);

        // Employer cannot access public routes
        $this->withHeader('Authorization', 'Bearer ' . $employerToken)
             ->getJson('/api/v1/categories')
             ->assertStatus(503);

        // Unauthenticated users cannot access public routes
        $this->getJson('/api/v1/categories')
             ->assertStatus(503);
    }

    /**
     * Verify that the login endpoint always works during maintenance.
     */
    public function test_login_always_works_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        // Admin can login
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@dreammore.com',
            'password' => 'password',
        ])->assertStatus(200);

        // Regular user can also login (but will be blocked from public routes)
        $freelancer = User::create([
            'name'     => 'Freelancer',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'freelancer@test.com',
            'password' => 'password',
        ])->assertStatus(200);
    }
}
