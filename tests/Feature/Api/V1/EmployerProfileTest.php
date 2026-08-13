<?php

namespace Tests\Feature\Api\V1;

use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_employer_can_view_own_profile(): void
    {
        $employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($employer, 'sanctum')
            ->getJson('/api/v1/employer/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $employer->id);

        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $employer->id,
        ]);
    }

    public function test_authenticated_employer_can_update_profile(): void
    {
        $employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $updateData = [
            'company_name' => 'Acme Innovation Technologies',
            'company_description' => 'Leading provider of enterprise AI cloud solutions.',
            'website' => 'https://acme.example.com',
            'industry' => 'Software & AI',
            'company_size' => '50-200 employees',
            'location' => 'San Francisco, CA',
            'phone' => '+1 (555) 234-5678',
            'bio' => 'Empowering businesses with AI.',
        ];

        $response = $this->actingAs($employer, 'sanctum')
            ->putJson('/api/v1/employer/profile', $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'Acme Innovation Technologies')
            ->assertJsonPath('data.industry', 'Software & AI')
            ->assertJsonPath('data.company_size', '50-200 employees');

        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $employer->id,
            'company_name' => 'Acme Innovation Technologies',
            'industry' => 'Software & AI',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $employer->id,
            'phone' => '+1 (555) 234-5678',
        ]);
    }

    public function test_unauthenticated_user_cannot_access_protected_employer_profile(): void
    {
        $response = $this->getJson('/api/v1/employer/profile');

        $response->assertStatus(401);
    }

    public function test_freelancer_cannot_modify_employer_profile(): void
    {
        $freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($freelancer, 'sanctum')
            ->putJson('/api/v1/employer/profile', [
                'company_name' => 'Attempted Freelancer Takeover',
            ]);

        $response->assertStatus(403);
    }

    public function test_validation_errors_handled_correctly(): void
    {
        $employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($employer, 'sanctum')
            ->putJson('/api/v1/employer/profile', [
                'website' => 'invalid-url-string',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['website']);
    }

    public function test_anyone_can_view_public_employer_profile(): void
    {
        $employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        EmployerProfile::create([
            'user_id' => $employer->id,
            'company_name' => 'Global Ventures LLC',
            'industry' => 'Fintech',
            'company_size' => '10-50 employees',
        ]);

        $response = $this->getJson('/api/v1/employers/' . $employer->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'Global Ventures LLC')
            ->assertJsonPath('data.industry', 'Fintech');
    }
}
