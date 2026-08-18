<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    /**
     * Create a standalone job owned by the given employer.
     */
    private function createJob(User $employer, array $overrides = []): Job
    {
        return Job::create(array_merge([
            'employer_id' => $employer->id,
            'title' => 'Build a Laravel E-Commerce Site',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need an experienced Laravel developer in Addis Ababa.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'location' => 'Addis Ababa',
        ], $overrides));
    }

    // ---------------------------------------------------------------- Creation

    public function test_employer_can_create_job(): void
    {
        $employer = $this->createContractUser('employer');

        $response = $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs', [
                'title' => 'Build a Laravel E-Commerce Site',
                'description' => 'Need an experienced Laravel developer in Addis Ababa.',
                'budget_type' => 'fixed',
                'min_budget' => 30000,
                'max_budget' => 60000,
                'location' => 'Addis Ababa',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Build a Laravel E-Commerce Site')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.currency', 'ETB')
            ->assertJsonPath('data.budget_type', 'fixed')
            ->assertJsonPath('data.min_budget', 30000)
            ->assertJsonPath('data.max_budget', 60000);

        $this->assertNotNull($response->json('data.published_at'));
        $this->assertNotNull($response->json('data.slug'));

        $this->assertDatabaseHas('marketplace_jobs', [
            'employer_id' => $employer->id,
            'title' => 'Build a Laravel E-Commerce Site',
            'status' => 'open',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_job(): void
    {
        $this->postJson('/api/v1/jobs', [
            'title' => 'Sneaky Job',
            'description' => 'Should not be created.',
            'budget_type' => 'fixed',
        ])->assertStatus(401);
    }

    public function test_freelancer_cannot_create_job(): void
    {
        $freelancer = $this->createContractUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs', [
                'title' => 'Sneaky Job',
                'description' => 'Freelancers cannot post jobs.',
                'budget_type' => 'fixed',
            ])
            ->assertStatus(403);
    }

    public function test_job_validation_errors_are_returned(): void
    {
        $employer = $this->createContractUser('employer');

        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'budget_type']);
    }

    public function test_employer_can_create_job_with_category_and_skills(): void
    {
        $employer = $this->createContractUser('employer');
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-development']);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel', 'category_id' => $category->id]);

        $response = $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs', [
                'title' => 'Laravel Developer Needed',
                'description' => 'Build APIs with Laravel.',
                'budget_type' => 'hourly',
                'min_budget' => 500,
                'max_budget' => 1500,
                'category_id' => $category->id,
                'skills' => [$skill->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.skills.0.id', $skill->id)
            ->assertJsonPath('data.skills.0.name', 'Laravel');

        $jobId = $response->json('data.id');
        $this->assertDatabaseHas('job_skills', [
            'job_id' => $jobId,
            'skill_id' => $skill->id,
        ]);
    }

    // ----------------------------------------------------------------- Reading

    public function test_public_can_browse_open_jobs(): void
    {
        $employer = $this->createContractUser('employer');
        $openJob = $this->createJob($employer);
        $this->createJob($employer, ['status' => 'closed']);

        $response = $this->getJson('/api/v1/jobs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $jobs = $response->json('data');
        $this->assertCount(1, $jobs);
        $this->assertEquals($openJob->id, $jobs[0]['id']);
    }

    public function test_public_can_view_open_job(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->getJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $job->id)
            ->assertJsonPath('data.status', 'open');
    }

    public function test_closed_job_is_hidden_from_public(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer, ['status' => 'closed']);

        $this->getJson('/api/v1/jobs/' . $job->id)->assertStatus(404);
    }

    public function test_employer_can_view_own_closed_job(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer, ['status' => 'closed']);

        $this->actingAsSanctum($employer)
            ->getJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_non_owner_cannot_view_closed_job(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $job = $this->createJob($employer, ['status' => 'closed']);

        $this->actingAsSanctum($otherEmployer)
            ->getJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(404);
    }

    public function test_nonexistent_job_returns_404(): void
    {
        $this->getJson('/api/v1/jobs/999999')->assertStatus(404);
    }

    public function test_employer_can_list_own_jobs(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $ownOpen = $this->createJob($employer);
        $ownClosed = $this->createJob($employer, ['status' => 'closed']);
        $this->createJob($otherEmployer);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/employer/jobs');

        $response->assertStatus(200);

        $jobs = $response->json('data');
        $this->assertCount(2, $jobs);
        $ids = collect($jobs)->pluck('id')->all();
        $this->assertContains($ownOpen->id, $ids);
        $this->assertContains($ownClosed->id, $ids);
    }

    public function test_freelancer_cannot_list_employer_jobs(): void
    {
        $freelancer = $this->createContractUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/employer/jobs')
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------- Updating

    public function test_employer_can_update_own_job(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($employer)
            ->putJson('/api/v1/jobs/' . $job->id, [
                'title' => 'Updated Job Title',
                'description' => 'Updated description.',
                'budget_type' => 'hourly',
                'max_budget' => 90000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Job Title')
            ->assertJsonPath('data.budget_type', 'hourly')
            ->assertJsonPath('data.max_budget', 90000);

        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $job->id,
            'title' => 'Updated Job Title',
        ]);
    }

    public function test_employer_cannot_update_another_employers_job(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($otherEmployer)
            ->putJson('/api/v1/jobs/' . $job->id, [
                'title' => 'Hijacked',
                'description' => 'Should fail.',
                'budget_type' => 'fixed',
            ])
            ->assertStatus(403);
    }

    public function test_freelancer_cannot_update_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->putJson('/api/v1/jobs/' . $job->id, [
                'title' => 'Hijacked',
                'description' => 'Should fail.',
                'budget_type' => 'fixed',
            ])
            ->assertStatus(403);
    }

    public function test_completed_job_cannot_be_updated(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer, ['status' => 'completed']);

        $this->actingAsSanctum($employer)
            ->putJson('/api/v1/jobs/' . $job->id, [
                'title' => 'Too Late',
                'description' => 'Should fail.',
                'budget_type' => 'fixed',
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------- Close / Reopen

    public function test_employer_can_close_open_job(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/close');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $job->id,
            'status' => 'closed',
        ]);
    }

    public function test_employer_cannot_close_another_employers_job(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($otherEmployer)
            ->postJson('/api/v1/jobs/' . $job->id . '/close')
            ->assertStatus(403);
    }

    public function test_freelancer_cannot_close_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/jobs/' . $job->id . '/close')
            ->assertStatus(403);
    }

    public function test_closed_job_can_be_reopened(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer, ['status' => 'closed']);

        $response = $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $job->id . '/reopen');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'open');

        $this->assertNotNull($response->json('data.published_at'));
        $this->assertDatabaseHas('marketplace_jobs', [
            'id' => $job->id,
            'status' => 'open',
        ]);
    }

    public function test_invalid_job_status_transitions_are_rejected(): void
    {
        $employer = $this->createContractUser('employer');

        // Reopening an already-open job is invalid.
        $open = $this->createJob($employer);
        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $open->id . '/reopen')
            ->assertStatus(422);

        // Closing an already-closed job is invalid.
        $closed = $this->createJob($employer, ['status' => 'closed']);
        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $closed->id . '/close')
            ->assertStatus(422);

        // Reopening a completed job is invalid.
        $completed = $this->createJob($employer, ['status' => 'completed']);
        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $completed->id . '/reopen')
            ->assertStatus(422);

        // Closing a draft job is invalid.
        $draft = $this->createJob($employer, ['status' => 'draft']);
        $this->actingAsSanctum($employer)
            ->postJson('/api/v1/jobs/' . $draft->id . '/close')
            ->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_close_job(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->postJson('/api/v1/jobs/' . $job->id . '/close')->assertStatus(401);
    }

    // ---------------------------------------------------------------- Deletion

    public function test_employer_can_delete_own_job_without_proposals(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($employer)
            ->deleteJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(200);

        $this->assertDatabaseMissing('marketplace_jobs', ['id' => $job->id]);
    }

    public function test_employer_cannot_delete_job_with_proposals(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I would love to work on this.',
            'bid_amount' => 45000,
            'estimated_duration' => '4 weeks',
        ]);

        $this->actingAsSanctum($employer)
            ->deleteJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('marketplace_jobs', ['id' => $job->id]);
    }

    public function test_employer_cannot_delete_another_employers_job(): void
    {
        $employer = $this->createContractUser('employer');
        $otherEmployer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($otherEmployer)
            ->deleteJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(403);
    }

    public function test_freelancer_cannot_delete_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        $job = $this->createJob($employer);

        $this->actingAsSanctum($freelancer)
            ->deleteJson('/api/v1/jobs/' . $job->id)
            ->assertStatus(403);
    }

    // --------------------------------------------------------------- Filtering

    public function test_jobs_can_be_filtered_by_search(): void
    {
        $employer = $this->createContractUser('employer');
        $this->createJob($employer, [
            'title' => 'Laravel API Developer',
            'description' => 'Build REST APIs with Laravel.',
        ]);
        $this->createJob($employer, [
            'title' => 'Flutter Mobile Developer',
            'description' => 'Build cross-platform mobile apps.',
        ]);

        $response = $this->getJson('/api/v1/jobs?search=Laravel');

        $response->assertStatus(200);
        $jobs = $response->json('data');
        $this->assertCount(1, $jobs);
        $this->assertEquals('Laravel API Developer', $jobs[0]['title']);
    }

    public function test_jobs_can_be_filtered_by_category(): void
    {
        $employer = $this->createContractUser('employer');
        $category = Category::create(['name' => 'Mobile', 'slug' => 'mobile']);
        $this->createJob($employer, ['category_id' => $category->id]);
        $this->createJob($employer);

        $response = $this->getJson('/api/v1/jobs?category_id=' . $category->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_jobs_can_be_filtered_by_budget(): void
    {
        $employer = $this->createContractUser('employer');
        $cheap = $this->createJob($employer, [
            'title' => 'Small Task',
            'min_budget' => 1000,
            'max_budget' => 5000,
        ]);
        $expensive = $this->createJob($employer, [
            'title' => 'Big Project',
            'min_budget' => 50000,
            'max_budget' => 100000,
        ]);

        $response = $this->getJson('/api/v1/jobs?min_budget=10000');

        $response->assertStatus(200);
        $jobs = $response->json('data');
        $this->assertCount(1, $jobs);
        $this->assertEquals($expensive->id, $jobs[0]['id']);
        $this->assertNotEquals($cheap->id, $jobs[0]['id']);
    }

    public function test_jobs_browse_is_paginated_with_meta(): void
    {
        $employer = $this->createContractUser('employer');
        foreach (range(1, 3) as $i) {
            $this->createJob($employer, ['title' => 'Job Number ' . $i]);
        }

        $response = $this->getJson('/api/v1/jobs?page=1');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(3, $response->json('meta.total'));
        $this->assertEquals(15, $response->json('meta.per_page'));
    }

    // ---------------------------------------------------------------- Security

    public function test_job_response_omits_sensitive_data(): void
    {
        $employer = $this->createContractUser('employer');
        $job = $this->createJob($employer);

        $response = $this->getJson('/api/v1/jobs/' . $job->id);

        $response->assertStatus(200);
        $payload = $response->json('data');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('email', $payload['employer']);
        $this->assertArrayNotHasKey('password', $payload['employer']);
    }
}
