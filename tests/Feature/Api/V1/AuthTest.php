<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_freelancer_can_register_successfully(): void
    {
        $payload = [
            'name' => 'John Freelancer',
            'email' => 'john@example.com',
            'password' => 'password123',
            'role' => 'freelancer',
            'phone' => '+1234567890',
            'bio' => 'Passionate software developer',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                     'message' => 'User registered successfully.',
                 ])
                 ->assertJsonStructure([
                     'data' => [
                         'user' => ['id', 'name', 'email', 'role', 'status'],
                         'token',
                     ],
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'role' => 'freelancer',
        ]);
        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $response->json('data.user.id'),
        ]);
    }

    public function test_employer_can_register_successfully(): void
    {
        $payload = [
            'name' => 'Jane Employer',
            'email' => 'jane@company.com',
            'password' => 'password123',
            'role' => 'employer',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@company.com',
            'role' => 'employer',
        ]);
        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $response->json('data.user.id'),
        ]);
    }

    public function test_register_validation_fails_with_invalid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => '123',
            'role' => 'invalid-role',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'freelancer',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'User authenticated successfully.',
                 ])
                 ->assertJsonStructure([
                     'data' => ['user', 'token'],
                 ]);
    }

    public function test_user_cannot_login_with_incorrect_password(): void
    {
        User::create([
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'freelancer',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::create([
            'name' => 'Current User',
            'email' => 'current@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'freelancer',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'email' => 'current@example.com',
                         'role' => 'freelancer',
                     ],
                 ]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'freelancer',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'User logged out successfully.',
                 ]);

        $this->assertCount(0, $user->tokens);
    }

    public function test_role_authorization_middleware(): void
    {
        $freelancer = User::create([
            'name' => 'Free Lancer',
            'email' => 'free@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'freelancer',
        ]);

        $employer = User::create([
            'name' => 'Emp Loyer',
            'email' => 'emp@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'employer',
        ]);

        // Freelancer tries accessing freelancer dashboard -> 200
        $this->actingAs($freelancer, 'sanctum')
             ->getJson('/api/v1/freelancer/dashboard-test')
             ->assertStatus(200);

        // Freelancer tries accessing employer dashboard -> 403
        $this->actingAs($freelancer, 'sanctum')
             ->getJson('/api/v1/employer/dashboard-test')
             ->assertStatus(403);

        // Employer tries accessing employer dashboard -> 200
        $this->actingAs($employer, 'sanctum')
             ->getJson('/api/v1/employer/dashboard-test')
             ->assertStatus(200);

        // Employer tries accessing freelancer dashboard -> 403
        $this->actingAs($employer, 'sanctum')
             ->getJson('/api/v1/freelancer/dashboard-test')
             ->assertStatus(403);
    }
}
