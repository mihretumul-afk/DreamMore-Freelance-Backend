<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_freelancer_can_fetch_own_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/freelancer/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.availability_status', 'available');

        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_freelancer_can_update_profile_and_sync_skills(): void
    {
        $user = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Web Development',
            'slug' => 'web-development',
        ]);

        $skill1 = Skill::create([
            'category_id' => $category->id,
            'name' => 'React',
            'slug' => 'react',
        ]);

        $skill2 = Skill::create([
            'category_id' => $category->id,
            'name' => 'Laravel',
            'slug' => 'laravel',
        ]);

        $updateData = [
            'headline' => 'Senior Full Stack Engineer',
            'overview' => 'Passionate software architect with 7+ years of experience.',
            'hourly_rate' => 85.00,
            'experience_level' => 'expert',
            'location' => 'San Francisco, CA',
            'github_url' => 'https://github.com/testuser',
            'availability_status' => 'available',
            'skills' => [
                ['id' => $skill1->id, 'years_of_experience' => 5],
                ['id' => $skill2->id, 'years_of_experience' => 4],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/freelancer/profile', $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.headline', 'Senior Full Stack Engineer')
            ->assertJsonPath('data.hourly_rate', 85)
            ->assertJsonPath('data.experience_level', 'expert')
            ->assertJsonCount(2, 'data.skills');

        $this->assertDatabaseHas('freelancer_profiles', [
            'user_id' => $user->id,
            'headline' => 'Senior Full Stack Engineer',
            'hourly_rate' => 85.00,
            'experience_level' => 'expert',
        ]);

        $profile = FreelancerProfile::where('user_id', $user->id)->first();
        $this->assertCount(2, $profile->skills);
    }

    public function test_employer_cannot_update_freelancer_profile(): void
    {
        $employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($employer, 'sanctum')
            ->putJson('/api/v1/freelancer/profile', [
                'headline' => 'Trying to pretend to be freelancer',
            ]);

        $response->assertStatus(403);
    }

    public function test_anyone_can_view_public_freelancer_profile(): void
    {
        $freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $profile = FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'headline' => 'Fullstack Dev',
            'overview' => 'Building modern web apps',
            'hourly_rate' => 60.00,
            'experience_level' => 'intermediate',
        ]);

        $response = $this->getJson('/api/v1/freelancers/' . $freelancer->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.headline', 'Fullstack Dev')
            ->assertJsonPath('data.hourly_rate', 60);
    }
}
