<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvancedSearchTest extends TestCase
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

    private function createFreelancer(array $profileOverrides = [], array $skillNames = []): FreelancerProfile
    {
        $user = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
            'name' => $profileOverrides['name'] ?? 'Test Freelancer',
        ]);
        unset($profileOverrides['name']);

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

        if (! empty($skillNames)) {
            $category = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
            $syncData = [];
            foreach ($skillNames as $skillName) {
                $skill = Skill::create([
                    'category_id' => $category->id,
                    'name' => $skillName,
                    'slug' => Str::slug($skillName).'-'.Str::random(4),
                ]);
                $syncData[$skill->id] = ['years_of_experience' => 3];
            }
            $profile->skills()->sync($syncData);
        }

        return $profile;
    }

    // ─── Advanced Job Search ─────────────────────────────────────────

    public function test_jobs_can_be_filtered_by_skill(): void
    {
        $employer = $this->createUser('employer');
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
        $skill = Skill::create(['category_id' => $category->id, 'name' => 'React', 'slug' => 'react-'.Str::random(4)]);

        $reactJob = $this->createJob($employer, ['title' => 'React Developer Needed']);
        $reactJob->skills()->attach($skill->id);

        $otherJob = $this->createJob($employer, ['title' => 'Python Developer Needed']);

        $response = $this->getJson('/api/v1/jobs?skill_id=' . $skill->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($reactJob->id, $data[0]['id']);
    }

    public function test_jobs_search_can_match_skill_names(): void
    {
        $employer = $this->createUser('employer');
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
        $skill = Skill::create(['category_id' => $category->id, 'name' => 'Vue.js', 'slug' => 'vue-'.Str::random(4)]);

        $vueJob = $this->createJob($employer, ['title' => 'Frontend Project']);
        $vueJob->skills()->attach($skill->id);

        $otherJob = $this->createJob($employer, ['title' => 'Backend Project']);

        $response = $this->getJson('/api/v1/jobs?search=Vue');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($vueJob->id, $data[0]['id']);
    }

    public function test_jobs_search_can_match_category_name(): void
    {
        $employer = $this->createUser('employer');
        $category = Category::create(['name' => 'Mobile Development', 'slug' => 'mobile-'.Str::random(4)]);

        $mobileJob = $this->createJob($employer, [
            'title' => 'Build an App',
            'category_id' => $category->id,
        ]);
        $otherJob = $this->createJob($employer, ['title' => 'Web Project']);

        $response = $this->getJson('/api/v1/jobs?search=Mobile');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($mobileJob->id, $data[0]['id']);
    }

    public function test_jobs_can_be_sorted_by_budget(): void
    {
        $employer = $this->createUser('employer');
        $cheap = $this->createJob($employer, [
            'title' => 'Cheap Job',
            'min_budget' => 1000,
            'max_budget' => 5000,
        ]);
        $expensive = $this->createJob($employer, [
            'title' => 'Expensive Job',
            'min_budget' => 50000,
            'max_budget' => 100000,
        ]);

        $response = $this->getJson('/api/v1/jobs?sort=budget&direction=desc');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($expensive->id, $data[0]['id']);
        $this->assertEquals($cheap->id, $data[1]['id']);
    }

    public function test_jobs_sort_defaults_to_newest(): void
    {
        $employer = $this->createUser('employer');

        // Create old job and backdate its timestamp.
        $old = $this->createJob($employer, ['title' => 'Old Job']);
        DB::table('marketplace_jobs')->where('id', $old->id)
            ->update(['created_at' => now()->subDays(5)]);

        // Create new job with current timestamp.
        $new = $this->createJob($employer, ['title' => 'New Job']);

        $response = $this->getJson('/api/v1/jobs');

        $response->assertStatus(200);
        $data = $response->json('data');
        // New job should appear before old job (sorted by created_at desc).
        $titles = collect($data)->pluck('title')->all();
        $newIndex = array_search('New Job', $titles);
        $oldIndex = array_search('Old Job', $titles);
        $this->assertNotFalse($newIndex, 'New Job not found in results');
        $this->assertNotFalse($oldIndex, 'Old Job not found in results');
        $this->assertLessThan($oldIndex, $newIndex, 'New job should appear before old job');
    }

    public function test_jobs_combined_filters_work(): void
    {
        $employer = $this->createUser('employer');
        $category = Category::create(['name' => 'Design', 'slug' => 'design-'.Str::random(4)]);
        $skill = Skill::create(['category_id' => $category->id, 'name' => 'Figma', 'slug' => 'figma-'.Str::random(4)]);

        $match = $this->createJob($employer, [
            'title' => 'Figma Design Project',
            'budget_type' => 'hourly',
            'experience_level' => 'expert',
            'location_type' => 'remote',
            'category_id' => $category->id,
            'min_budget' => 500,
            'max_budget' => 2000,
        ]);
        $match->skills()->attach($skill->id);

        $this->createJob($employer, [
            'title' => 'PHP Backend',
            'budget_type' => 'fixed',
            'experience_level' => 'entry',
        ]);

        $response = $this->getJson('/api/v1/jobs?search=Figma&budget_type=hourly&experience_level=expert&location_type=remote&min_budget=100');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($match->id, $data[0]['id']);
    }

    // ─── Advanced Freelancer Search ──────────────────────────────────

    public function test_freelancers_can_be_filtered_by_location(): void
    {
        $this->createFreelancer(['headline' => 'Addis Dev', 'location' => 'Addis Ababa']);
        $this->createFreelancer(['headline' => 'Bahir Dar Dev', 'location' => 'Bahir Dar']);

        $response = $this->getJson('/api/v1/freelancers?location=Addis');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertContains('Addis Dev', $headlines);
        $this->assertNotContains('Bahir Dar Dev', $headlines);
    }

    public function test_freelancers_can_be_filtered_by_min_rating(): void
    {
        $this->createFreelancer(['headline' => 'High Rated', 'rating' => 4.8]);
        $this->createFreelancer(['headline' => 'Low Rated', 'rating' => 2.0]);

        $response = $this->getJson('/api/v1/freelancers?min_rating=4.0');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertContains('High Rated', $headlines);
        $this->assertNotContains('Low Rated', $headlines);
    }

    public function test_freelancers_can_be_sorted_by_hourly_rate(): void
    {
        $this->createFreelancer(['headline' => 'Cheap', 'hourly_rate' => 200.00]);
        $this->createFreelancer(['headline' => 'Expensive', 'hourly_rate' => 2000.00]);

        $response = $this->getJson('/api/v1/freelancers?sort=hourly_rate&direction=desc');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertEquals('Expensive', $headlines->first());
        $this->assertEquals('Cheap', $headlines->last());
    }

    public function test_freelancers_can_be_sorted_by_completed_jobs(): void
    {
        $this->createFreelancer(['headline' => 'Experienced', 'completed_jobs_count' => 50]);
        $this->createFreelancer(['headline' => 'Newcomer', 'completed_jobs_count' => 2]);

        $response = $this->getJson('/api/v1/freelancers?sort=completed_jobs_count&direction=desc');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertEquals('Experienced', $headlines->first());
    }

    public function test_freelancers_combined_filters_work(): void
    {
        $this->createFreelancer([
            'headline' => 'Expert Addis React Dev',
            'experience_level' => 'expert',
            'availability_status' => 'available',
            'location' => 'Addis Ababa',
            'hourly_rate' => 1500.00,
        ], ['React']);

        $this->createFreelancer([
            'headline' => 'Entry Bahir Python Dev',
            'experience_level' => 'entry',
            'availability_status' => 'busy',
            'location' => 'Bahir Dar',
            'hourly_rate' => 300.00,
        ], ['Python']);

        $response = $this->getJson('/api/v1/freelancers?search=React&experience_level=expert&availability_status=available&location=Addis&min_hourly_rate=1000');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Expert Addis React Dev', $data[0]['headline']);
    }

    public function test_freelancers_invalid_sort_falls_back_to_rating(): void
    {
        $this->createFreelancer(['headline' => 'High Rated', 'rating' => 5.0]);
        $this->createFreelancer(['headline' => 'Low Rated', 'rating' => 1.0]);

        $response = $this->getJson('/api/v1/freelancers?sort=invalid_column&direction=desc');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertEquals('High Rated', $headlines->first());
    }
}
