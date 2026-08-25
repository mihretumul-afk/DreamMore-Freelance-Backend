<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;

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
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;
    }

    public function test_public_routes_work_when_maintenance_off(): void
    {
        AdminSetting::setValue('maintenance_mode', 'false', 'boolean');

        $this->getJson('/api/v1/platform-settings')
             ->assertStatus(200);
    }

    public function test_public_routes_blocked_when_maintenance_on(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $this->getJson('/api/v1/categories')
             ->assertStatus(503)
             ->assertJson([
                 'success' => false,
                 'maintenance' => true,
             ]);
    }

    public function test_platform_settings_accessible_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $this->getJson('/api/v1/platform-settings')
             ->assertStatus(200);
    }

    public function test_auth_endpoints_accessible_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        // Login should still work so admin can access the panel
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ])->assertStatus(200);
    }

    public function test_admin_bypasses_maintenance_mode(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->getJson('/api/v1/categories')
             ->assertStatus(200);
    }

    public function test_health_endpoint_accessible_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $this->getJson('/api/v1/health')
             ->assertStatus(200);
    }

    public function test_employer_cannot_access_public_routes_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $employer = User::create([
            'name'     => 'Employer',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $employerToken = $employer->createToken('employer_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $employerToken)
             ->getJson('/api/v1/categories')
             ->assertStatus(503);
    }

    public function test_freelancer_cannot_access_public_routes_during_maintenance(): void
    {
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $freelancer = User::create([
            'name'     => 'Freelancer',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $freelancerToken = $freelancer->createToken('freelancer_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $freelancerToken)
             ->getJson('/api/v1/categories')
             ->assertStatus(503);
    }
}
