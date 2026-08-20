<?php

namespace Tests\Feature\Api\V1;

use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\SavedJob;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SavedJobTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'freelancer'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createJob(User $employer, array $overrides = []): Job
    {
        return Job::create(array_merge([
            'employer_id' => $employer->id,
            'title' => 'Build a Laravel API',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need a skilled Laravel developer.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'location' => 'Addis Ababa',
        ], $overrides));
    }

    private function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    // ---- Save a job ----

    public function test_authenticated_user_can_save_a_job(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/save");

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', $job->id);

        $this->assertDatabaseHas('saved_jobs', [
            'user_id' => $freelancer->id,
            'job_id' => $job->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_save_job(): void
    {
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        $this->postJson("/api/v1/jobs/{$job->id}/save")
            ->assertStatus(401);
    }

    public function test_duplicate_save_is_prevented(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/save")
            ->assertStatus(201);

        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/save")
            ->assertStatus(422);
    }

    public function test_invalid_job_cannot_be_saved(): void
    {
        $freelancer = $this->createUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/999999/save')
            ->assertStatus(404);
    }

    public function test_non_open_job_returns_404(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer, ['status' => 'closed']);

        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/save")
            ->assertStatus(404);
    }

    // ---- Unsave a job ----

    public function test_user_can_unsave_a_job(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $job->id]);

        $response = $this->actingAsSanctum($freelancer)
            ->deleteJson("/api/v1/jobs/{$job->id}/save");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('saved_jobs', [
            'user_id' => $freelancer->id,
            'job_id' => $job->id,
        ]);
    }

    public function test_unsave_nonexistent_entry_returns_404(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->deleteJson("/api/v1/jobs/{$job->id}/save")
            ->assertStatus(404);
    }

    // ---- List saved jobs ----

    public function test_user_can_list_own_saved_jobs(): void
    {
        $freelancer = $this->createUser('freelancer');
        $otherFreelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');

        $job1 = $this->createJob($employer, ['title' => 'Job 1']);
        $job2 = $this->createJob($employer, ['title' => 'Job 2']);

        SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $job1->id]);
        SavedJob::create(['user_id' => $otherFreelancer->id, 'job_id' => $job2->id]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/saved-jobs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($job1->id, $data[0]['job_id']);
    }

    public function test_user_cannot_access_another_users_saved_jobs(): void
    {
        $freelancer = $this->createUser('freelancer');
        $otherFreelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');

        $job = $this->createJob($employer);
        SavedJob::create(['user_id' => $otherFreelancer->id, 'job_id' => $job->id]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/saved-jobs');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(0, $data);
    }

    public function test_unauthenticated_user_cannot_list_saved_jobs(): void
    {
        $this->getJson('/api/v1/saved-jobs')
            ->assertStatus(401);
    }

    // ---- Check saved status ----

    public function test_user_can_check_saved_status(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $job->id]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson("/api/v1/jobs/{$job->id}/saved");

        $response->assertStatus(200)
            ->assertJsonPath('data.saved', true);
    }

    // ---- Pagination ----

    public function test_saved_jobs_are_paginated(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');

        foreach (range(1, 3) as $i) {
            $job = $this->createJob($employer, ['title' => "Job {$i}"]);
            SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $job->id]);
        }

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/saved-jobs?page=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15);
    }

    // ---- Role authorization ----

    public function test_employer_can_also_save_jobs(): void
    {
        $employer = $this->createUser('employer');
        $otherEmployer = $this->createUser('employer');
        $job = $this->createJob($otherEmployer);

        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/jobs/{$job->id}/save");

        $response->assertStatus(201);
    }

    // ---- Response structure ----

    public function test_saved_job_response_has_expected_structure(): void
    {
        $freelancer = $this->createUser('freelancer');
        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);

        SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $job->id]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/saved-jobs');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('user_id', $data[0]);
        $this->assertArrayHasKey('job_id', $data[0]);
        $this->assertArrayHasKey('saved_at', $data[0]);
        $this->assertArrayHasKey('job', $data[0]);
    }
}
