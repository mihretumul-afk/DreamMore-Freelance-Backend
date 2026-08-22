<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $employer;
    protected User $freelancer;
    protected Category $category;
    protected Skill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        // Create employer
        $this->employer = User::factory()->create(['role' => 'employer', 'status' => 'active']);

        // Create freelancer with profile
        $this->freelancer = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);
        FreelancerProfile::create([
            'user_id' => $this->freelancer->id,
            'headline' => 'Expert React Developer',
            'overview' => 'Full-stack developer specializing in React and Laravel.',
            'location' => 'Addis Ababa',
            'hourly_rate' => 500,
            'experience_level' => 'expert',
            'rating' => 4.8,
            'availability_status' => 'available',
        ]);

        // Create category
        $this->category = Category::create(['name' => 'Web Development', 'slug' => 'web-development']);

        // Create skill
        $this->skill = Skill::create(['name' => 'React', 'slug' => 'react', 'category_id' => $this->category->id]);

        // Create a job
        $job = Job::create([
            'employer_id' => $this->employer->id,
            'category_id' => $this->category->id,
            'title' => 'React Frontend Developer Needed',
            'slug' => 'react-frontend-developer-needed',
            'description' => 'We need an experienced React developer for a web application.',
            'budget_type' => 'fixed',
            'min_budget' => 10000,
            'max_budget' => 25000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $job->skills()->sync([$this->skill->id]);
    }

    public function test_search_endpoint_requires_query(): void
    {
        $response = $this->getJson('/api/v1/search');
        $response->assertStatus(422);
    }

    public function test_search_returns_empty_for_no_matches(): void
    {
        $response = $this->getJson('/api/v1/search?q=xyznonexistent');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 0)
            ->assertJsonPath('data.freelancers_total', 0);
    }

    public function test_search_finds_jobs_by_title(): void
    {
        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1)
            ->assertJsonPath('data.jobs.0.title', 'React Frontend Developer Needed');
    }

    public function test_search_finds_jobs_by_skill_name(): void
    {
        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1);
    }

    public function test_search_finds_jobs_by_category_name(): void
    {
        $response = $this->getJson('/api/v1/search?q=web+development');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1);
    }

    public function test_search_finds_freelancers_by_headline(): void
    {
        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonPath('data.freelancers_total', 1)
            ->assertJsonPath('data.freelancers.0.headline', 'Expert React Developer');
    }

    public function test_search_finds_freelancers_by_name(): void
    {
        $name = $this->freelancer->name;
        $response = $this->getJson('/api/v1/search?q=' . urlencode($name));
        $response->assertOk()
            ->assertJsonPath('data.freelancers_total', 1);
    }

    public function test_search_finds_freelancers_by_overview(): void
    {
        $response = $this->getJson('/api/v1/search?q=full-stack');
        $response->assertOk()
            ->assertJsonPath('data.freelancers_total', 1);
    }

    public function test_search_finds_freelancers_by_location(): void
    {
        $response = $this->getJson('/api/v1/search?q=addis');
        $response->assertOk()
            ->assertJsonPath('data.freelancers_total', 1);
    }

    public function test_search_is_case_insensitive(): void
    {
        $response = $this->getJson('/api/v1/search?q=REACT');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1)
            ->assertJsonPath('data.freelancers_total', 1);
    }

    public function test_search_supports_partial_words(): void
    {
        $response = $this->getJson('/api/v1/search?q=front');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1);
    }

    public function test_search_supports_multiple_words(): void
    {
        $response = $this->getJson('/api/v1/search?q=react+developer');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1)
            ->assertJsonPath('data.freelancers_total', 1);
    }

    public function test_search_returns_both_jobs_and_freelancers(): void
    {
        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'jobs' => [],
                    'freelancers' => [],
                    'jobs_total',
                    'freelancers_total',
                ],
            ]);
    }

    public function test_search_ignores_extra_spaces(): void
    {
        $response = $this->getJson('/api/v1/search?q=' . urlencode('  react   developer  '));
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1);
    }

    public function test_search_handles_description_match(): void
    {
        $response = $this->getJson('/api/v1/search?q=frontend+application');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1);
    }

    public function test_search_per_page_parameter_works(): void
    {
        // Create more jobs
        for ($i = 0; $i < 5; $i++) {
            Job::create([
                'employer_id' => $this->employer->id,
                'category_id' => $this->category->id,
                'title' => "React Project {$i}",
                'slug' => "react-project-{$i}",
                'description' => "Another React job posting number {$i}.",
                'budget_type' => 'fixed',
                'min_budget' => 5000,
                'max_budget' => 15000,
                'experience_level' => 'entry',
                'location_type' => 'remote',
                'status' => 'open',
                'published_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/search?q=react&per_page=3');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 6) // original + 5 new
            ->assertJsonCount(3, 'data.jobs'); // limited to 3
    }

    public function test_search_only_returns_open_jobs_from_active_employers(): void
    {
        // Create a closed job
        Job::create([
            'employer_id' => $this->employer->id,
            'category_id' => $this->category->id,
            'title' => 'Closed React Job',
            'slug' => 'closed-react-job',
            'description' => 'This job is closed.',
            'budget_type' => 'fixed',
            'min_budget' => 5000,
            'max_budget' => 10000,
            'experience_level' => 'entry',
            'location_type' => 'remote',
            'status' => 'closed',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonPath('data.jobs_total', 1); // only the open one
    }

    public function test_search_only_returns_active_freelancers(): void
    {
        // Create inactive freelancer (suspended status)
        $inactive = User::factory()->create(['role' => 'freelancer', 'status' => 'suspended']);
        FreelancerProfile::create([
            'user_id' => $inactive->id,
            'headline' => 'React Expert (inactive)',
            'overview' => 'Was a React developer.',
            'availability_status' => 'available',
        ]);

        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonPath('data.freelancers_total', 1); // only the active one
    }

    public function test_search_results_contain_expected_resource_fields(): void
    {
        $response = $this->getJson('/api/v1/search?q=react');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'jobs' => [
                        [
                            'id',
                            'title',
                            'slug',
                            'description',
                            'budget_type',
                            'min_budget',
                            'max_budget',
                            'category',
                            'skills',
                            'employer',
                        ],
                    ],
                    'freelancers' => [
                        [
                            'id',
                            'user_id',
                            'user',
                            'headline',
                            'overview',
                            'hourly_rate',
                            'skills',
                        ],
                    ],
                ],
            ]);
    }
}
