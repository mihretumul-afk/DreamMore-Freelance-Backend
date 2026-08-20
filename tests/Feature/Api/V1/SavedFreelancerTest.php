<?php

namespace Tests\Feature\Api\V1;

use App\Models\FreelancerProfile;
use App\Models\SavedFreelancer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedFreelancerTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'freelancer'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createFreelancerProfile(User $user): FreelancerProfile
    {
        return FreelancerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Senior Laravel Developer',
            'overview' => 'Experienced full-stack developer.',
            'hourly_rate' => 500,
            'experience_level' => 'expert',
            'availability_status' => 'available',
            'completed_jobs_count' => 12,
            'total_earnings' => 500000,
            'rating' => 4.8,
        ]);
    }

    private function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    // ---- Save a freelancer ----

    public function test_authenticated_user_can_save_freelancer(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/freelancers/{$profile->id}/save");

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.freelancer_profile_id', $profile->id);

        $this->assertDatabaseHas('saved_freelancers', [
            'user_id' => $employer->id,
            'freelancer_profile_id' => $profile->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_save_freelancer(): void
    {
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $this->postJson("/api/v1/freelancers/{$profile->id}/save")
            ->assertStatus(401);
    }

    public function test_duplicate_save_is_prevented(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $this->actingAsSanctum($employer)
            ->postJson("/api/v1/freelancers/{$profile->id}/save")
            ->assertStatus(201);

        $this->actingAsSanctum($employer)
            ->postJson("/api/v1/freelancers/{$profile->id}/save")
            ->assertStatus(422);
    }

    public function test_cannot_save_own_profile(): void
    {
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $this->actingAsSanctum($freelancerUser)
            ->postJson("/api/v1/freelancers/{$profile->id}/save")
            ->assertStatus(422);
    }

    public function test_invalid_freelancer_cannot_be_saved(): void
    {
        $employer = $this->createUser('employer');

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/freelancers/999999/save')
            ->assertStatus(404);
    }

    // ---- Unsave a freelancer ----

    public function test_user_can_unsave_freelancer(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        SavedFreelancer::create([
            'user_id' => $employer->id,
            'freelancer_profile_id' => $profile->id,
        ]);

        $response = $this->actingAsSanctum($employer)
            ->deleteJson("/api/v1/freelancers/{$profile->id}/save");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('saved_freelancers', [
            'user_id' => $employer->id,
            'freelancer_profile_id' => $profile->id,
        ]);
    }

    public function test_unsave_nonexistent_entry_returns_404(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $this->actingAsSanctum($employer)
            ->deleteJson("/api/v1/freelancers/{$profile->id}/save")
            ->assertStatus(404);
    }

    // ---- List saved freelancers ----

    public function test_user_can_list_own_saved_freelancers(): void
    {
        $employer = $this->createUser('employer');
        $otherEmployer = $this->createUser('employer');
        $freelancer1 = $this->createUser('freelancer');
        $freelancer2 = $this->createUser('freelancer');
        $profile1 = $this->createFreelancerProfile($freelancer1);
        $profile2 = $this->createFreelancerProfile($freelancer2);

        SavedFreelancer::create(['user_id' => $employer->id, 'freelancer_profile_id' => $profile1->id]);
        SavedFreelancer::create(['user_id' => $otherEmployer->id, 'freelancer_profile_id' => $profile2->id]);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($profile1->id, $data[0]['freelancer_profile_id']);
    }

    public function test_user_cannot_access_another_users_saved_freelancers(): void
    {
        $employer = $this->createUser('employer');
        $otherEmployer = $this->createUser('employer');
        $freelancer = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancer);

        SavedFreelancer::create([
            'user_id' => $otherEmployer->id,
            'freelancer_profile_id' => $profile->id,
        ]);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(0, $data);
    }

    public function test_unauthenticated_user_cannot_list_saved_freelancers(): void
    {
        $this->getJson('/api/v1/saved-freelancers')
            ->assertStatus(401);
    }

    // ---- Check saved status ----

    public function test_user_can_check_saved_status(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        SavedFreelancer::create([
            'user_id' => $employer->id,
            'freelancer_profile_id' => $profile->id,
        ]);

        $response = $this->actingAsSanctum($employer)
            ->getJson("/api/v1/freelancers/{$profile->id}/saved");

        $response->assertStatus(200)
            ->assertJsonPath('data.saved', true);
    }

    public function test_unsaved_freelancer_returns_false(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        $response = $this->actingAsSanctum($employer)
            ->getJson("/api/v1/freelancers/{$profile->id}/saved");

        $response->assertStatus(200)
            ->assertJsonPath('data.saved', false);
    }

    // ---- Pagination ----

    public function test_saved_freelancers_are_paginated(): void
    {
        $employer = $this->createUser('employer');

        foreach (range(1, 3) as $i) {
            $user = $this->createUser('freelancer');
            $profile = $this->createFreelancerProfile($user);
            SavedFreelancer::create([
                'user_id' => $employer->id,
                'freelancer_profile_id' => $profile->id,
            ]);
        }

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers?page=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15);
    }

    // ---- Role authorization ----

    public function test_freelancer_can_also_save_freelancers(): void
    {
        $freelancer = $this->createUser('freelancer');
        $otherFreelancer = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($otherFreelancer);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/freelancers/{$profile->id}/save");

        $response->assertStatus(201);
    }

    // ---- Response structure ----

    public function test_saved_freelancer_response_has_expected_structure(): void
    {
        $employer = $this->createUser('employer');
        $freelancerUser = $this->createUser('freelancer');
        $profile = $this->createFreelancerProfile($freelancerUser);

        SavedFreelancer::create([
            'user_id' => $employer->id,
            'freelancer_profile_id' => $profile->id,
        ]);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('user_id', $data[0]);
        $this->assertArrayHasKey('freelancer_profile_id', $data[0]);
        $this->assertArrayHasKey('saved_at', $data[0]);
        $this->assertArrayHasKey('freelancer', $data[0]);
    }
}
