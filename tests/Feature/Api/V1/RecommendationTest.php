<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\SavedFreelancer;
use App\Models\SavedJob;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationTest extends TestCase
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
            'title' => 'Build a Laravel E-Commerce Site',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Need an experienced Laravel developer in Addis Ababa.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'location' => 'Addis Ababa',
        ], $overrides));
    }

    private function createFreelancerWithProfile(array $profileOverrides = [], array $skillNames = []): array
    {
        $user = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
            'name' => $profileOverrides['name'] ?? 'Test Freelancer',
        ]);
        unset($profileOverrides['name']);

        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);

        $profile = FreelancerProfile::create(array_merge([
            'user_id' => $user->id,
            'headline' => 'Full Stack Developer',
            'overview' => 'Building modern web applications.',
            'hourly_rate' => 500.00,
            'experience_level' => 'intermediate',
            'location' => 'Addis Ababa',
            'availability_status' => 'available',
            'rating' => 4.5,
            'completed_jobs_count' => 10,
        ], $profileOverrides));

        $skills = [];
        if (! empty($skillNames)) {
            $syncData = [];
            foreach ($skillNames as $skillName) {
                $skill = Skill::create([
                    'category_id' => $category->id,
                    'name' => $skillName,
                    'slug' => Str::slug($skillName).'-'.Str::random(4),
                ]);
                $syncData[$skill->id] = ['years_of_experience' => 3];
                $skills[] = $skill;
            }
            $profile->skills()->sync($syncData);
        }

        return compact('user', 'profile', 'category', 'skills');
    }

    private function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    // ─── Job Recommendations ─────────────────────────────────────────

    public function test_freelancer_gets_job_recommendations(): void
    {
        $freelancerData = $this->createFreelancerWithProfile([], ['Laravel', 'React']);
        $freelancer = $freelancerData['user'];
        $category = $freelancerData['category'];

        $employer = $this->createUser('employer');

        // Job matching freelancer's skills.
        $matchingJob = $this->createJob($employer, [
            'title' => 'Laravel Developer Needed',
            'description' => 'Build APIs with Laravel and React.',
            'category_id' => $category->id,
        ]);
        $matchingJob->skills()->attach($freelancerData['skills'][0]->id);

        // Unrelated job.
        $this->createJob($employer, [
            'title' => 'Python Data Analyst',
            'description' => 'Analyze data with Python.',
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        $this->assertArrayHasKey('job', $data[0]);
        $this->assertArrayHasKey('reason', $data[0]);
        $this->assertArrayHasKey('score', $data[0]);
    }

    public function test_employer_cannot_get_job_recommendations(): void
    {
        $employer = $this->createUser('employer');

        $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/jobs')
            ->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_get_job_recommendations(): void
    {
        $this->getJson('/api/v1/recommendations/jobs')
            ->assertStatus(401);
    }

    public function test_empty_recommendation_state_works(): void
    {
        // Freelancer with no skills and no saved jobs.
        $user = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        FreelancerProfile::create([
            'user_id' => $user->id,
            'headline' => 'New Freelancer',
            'experience_level' => 'entry',
            'availability_status' => 'available',
            'rating' => 0,
            'completed_jobs_count' => 0,
        ]);

        $response = $this->actingAsSanctum($user)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Should return empty data since there are no matching signals.
        $this->assertEmpty($response->json('data'));
    }

    public function test_saved_jobs_influence_recommendations(): void
    {
        $freelancerData = $this->createFreelancerWithProfile([], ['Laravel']);
        $freelancer = $freelancerData['user'];

        $employer = $this->createUser('employer');

        // Create a job with skills similar to saved jobs.
        $savedCategory = Category::create(['name' => 'Mobile', 'slug' => 'mobile-'.Str::random(4)]);
        $savedSkill = Skill::create([
            'category_id' => $savedCategory->id,
            'name' => 'React Native',
            'slug' => 'react-native-'.Str::random(4),
        ]);

        $savedJob = $this->createJob($employer, [
            'title' => 'React Native App',
            'category_id' => $savedCategory->id,
        ]);
        $savedJob->skills()->attach($savedSkill->id);

        SavedJob::create(['user_id' => $freelancer->id, 'job_id' => $savedJob->id]);

        // Create a new job with similar skills.
        $newJob = $this->createJob($employer, [
            'title' => 'React Native Developer',
            'description' => 'Build mobile apps with React Native.',
        ]);
        $newJob->skills()->attach($savedSkill->id);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    public function test_recommendations_exclude_jobs_already_proposed_to(): void
    {
        $freelancerData = $this->createFreelancerWithProfile([], ['Laravel']);
        $freelancer = $freelancerData['user'];

        $employer = $this->createUser('employer');
        $job = $this->createJob($employer, [
            'title' => 'Laravel Project',
        ]);
        $job->skills()->attach($freelancerData['skills'][0]->id);

        // Freelancer already proposed to this job.
        \App\Models\Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_letter' => 'I can do this.',
            'bid_amount' => 45000,
            'estimated_duration' => '4 weeks',
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200);
        $data = $response->json('data');

        // The proposed job should NOT appear in recommendations.
        $jobIds = collect($data)->pluck('job.id')->all();
        $this->assertNotContains($job->id, $jobIds);
    }

    public function test_recommendation_reasons_are_explainable(): void
    {
        $freelancerData = $this->createFreelancerWithProfile(
            ['experience_level' => 'expert', 'location' => 'Addis Ababa'],
            ['Laravel']
        );
        $freelancer = $freelancerData['user'];

        $employer = $this->createUser('employer');
        $job = $this->createJob($employer, [
            'title' => 'Expert Laravel Developer',
            'description' => 'Need expert Laravel developer in Addis Ababa.',
            'experience_level' => 'expert',
            'location' => 'Addis Ababa',
            'category_id' => $freelancerData['category']->id,
        ]);
        $job->skills()->attach($freelancerData['skills'][0]->id);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data[0]['reason']);
        $this->assertStringContainsString('Laravel', $data[0]['reason']);
    }

    // ─── Freelancer Recommendations ──────────────────────────────────

    public function test_employer_gets_freelancer_recommendations(): void
    {
        $employer = $this->createUser('employer');

        // Create employer's job needing Laravel skills.
        $job = $this->createJob($employer, ['title' => 'Laravel Project']);
        $category = $freelancerData['category'] ?? Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $skill = Skill::create([
            'category_id' => $category->id,
            'name' => 'Laravel',
            'slug' => 'laravel-'.Str::random(4),
        ]);
        $job->skills()->attach($skill->id);

        // Create a matching freelancer.
        $matchingFreelancer = $this->createFreelancerWithProfile([], ['Laravel']);

        // Create a non-matching freelancer.
        $this->createFreelancerWithProfile(['headline' => 'Data Analyst'], ['SQL']);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        $this->assertArrayHasKey('freelancer', $data[0]);
        $this->assertArrayHasKey('reason', $data[0]);
        $this->assertArrayHasKey('score', $data[0]);
    }

    public function test_freelancer_cannot_get_freelancer_recommendations(): void
    {
        $freelancer = $this->createUser('freelancer');

        $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/freelancers')
            ->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_get_freelancer_recommendations(): void
    {
        $this->getJson('/api/v1/recommendations/freelancers')
            ->assertStatus(401);
    }

    public function test_saved_freelancers_influence_recommendations(): void
    {
        $employer = $this->createUser('employer');

        // Save a freelancer with React skills.
        $savedFreelancer = $this->createFreelancerWithProfile([], ['React']);
        SavedFreelancer::create([
            'user_id' => $employer->id,
            'freelancer_profile_id' => $savedFreelancer['profile']->id,
        ]);

        // Create another freelancer with similar skills.
        $similarFreelancer = $this->createFreelancerWithProfile(
            ['headline' => 'React Specialist'],
            ['React']
        );

        // Create a non-matching freelancer.
        $this->createFreelancerWithProfile(['headline' => 'PHP Dev'], ['PHP']);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // The React specialist should be ranked highly.
        $headlines = collect($data)->pluck('freelancer.headline');
        $this->assertContains('React Specialist', $headlines);
    }

    public function test_freelancer_recommendation_excludes_active_contracts(): void
    {
        $employer = $this->createUser('employer');

        // Create a freelancer currently under active contract.
        $activeFreelancer = $this->createFreelancerWithProfile([], ['Laravel']);
        $contractJob = $this->createJob($employer);
        $proposal = \App\Models\Proposal::create([
            'job_id' => $contractJob->id,
            'freelancer_id' => $activeFreelancer['user']->id,
            'cover_letter' => 'I can do this.',
            'bid_amount' => 50000,
            'estimated_duration' => '4 weeks',
        ]);
        \App\Models\Contract::create([
            'job_id' => $contractJob->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $activeFreelancer['user']->id,
            'title' => 'Active Contract',
            'agreed_rate' => 50000,
            'total_amount' => 50000,
            'status' => 'active',
        ]);

        // Create a available freelancer.
        $availableFreelancer = $this->createFreelancerWithProfile([], ['Laravel']);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');

        $response->assertStatus(200);
        $data = $response->json('data');

        // The active-contract freelancer should not appear.
        $userIds = collect($data)->pluck('freelancer.user_id')->all();
        $this->assertNotContains($activeFreelancer['user']->id, $userIds);
    }

    public function test_no_private_data_leakage_in_recommendations(): void
    {
        $employer = $this->createUser('employer');

        // Create freelancer's job.
        $job = $this->createJob($employer);
        $category = Category::create(['name' => 'Web', 'slug' => 'web-'.Str::random(4)]);
        $skill = Skill::create([
            'category_id' => $category->id,
            'name' => 'Laravel',
            'slug' => 'laravel-'.Str::random(4),
        ]);
        $job->skills()->attach($skill->id);

        $freelancerData = $this->createFreelancerWithProfile([], ['Laravel']);

        $response = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $item) {
            $this->assertArrayNotHasKey('email', $item['freelancer']);
            $this->assertArrayNotHasKey('password', $item['freelancer']);
            $this->assertArrayNotHasKey('token', $item['freelancer']);
            $this->assertArrayNotHasKey('total_earnings', $item['freelancer']);
        }
    }

    public function test_recommendation_score_is_positive_for_matches(): void
    {
        $freelancerData = $this->createFreelancerWithProfile([], ['Laravel']);
        $freelancer = $freelancerData['user'];

        $employer = $this->createUser('employer');
        $job = $this->createJob($employer);
        $job->skills()->attach($freelancerData['skills'][0]->id);

        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/recommendations/jobs');

        $response->assertStatus(200);
        $data = $response->json('data');

        if (count($data) > 0) {
            $this->assertGreaterThan(0, $data[0]['score']);
        }
    }
}
