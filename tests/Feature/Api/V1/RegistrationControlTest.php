<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_works_when_open(): void
    {
        AdminSetting::setValue('registration_open', 'true', 'boolean');

        $response = $this->postJson('/api/v1/auth/register', [
            'name'     => 'New User',
            'email'    => 'new@test.com',
            'password' => 'password123',
            'role'     => 'freelancer',
        ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }

    public function test_registration_blocked_when_closed(): void
    {
        AdminSetting::setValue('registration_open', 'false', 'boolean');

        $response = $this->postJson('/api/v1/auth/register', [
            'name'     => 'New User',
            'email'    => 'new@test.com',
            'password' => 'password123',
            'role'     => 'freelancer',
        ]);

        $response->assertStatus(403)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Registration is currently closed. Please try again later.',
                 ]);
    }

    public function test_employer_registration_blocked_when_closed(): void
    {
        AdminSetting::setValue('registration_open', 'false', 'boolean');

        $response = $this->postJson('/api/v1/auth/register', [
            'name'     => 'New Employer',
            'email'    => 'employer@test.com',
            'password' => 'password123',
            'role'     => 'employer',
        ]);

        $response->assertStatus(403)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Registration is currently closed. Please try again later.',
                 ]);
    }

    public function test_existing_users_can_still_login_when_registration_closed(): void
    {
        AdminSetting::setValue('registration_open', 'false', 'boolean');

        // Create an existing user
        \App\Models\User::create([
            'name'     => 'Existing User',
            'email'    => 'existing@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'existing@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }
}
