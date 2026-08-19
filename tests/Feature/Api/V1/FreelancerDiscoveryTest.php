<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreelancerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a freelancer user + profile, optionally with skills.
     */
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

    public function test_freelancers_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/freelancers')->assertStatus(200);
    }

    public function test_freelancers_are_returned_with_public_data_only(): void
    {
        $profile = $this->createFreelancer(
            ['headline' => 'Senior Laravel Developer'],
            ['Laravel', 'React']
        );

        $response = $this->getJson('/api/v1/freelancers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $items = collect($response->json('data'));
        $found = $items->firstWhere('id', $profile->id);

        $this->assertNotNull($found);
        $this->assertEquals('Senior Laravel Developer', $found['headline']);
        $this->assertEquals('intermediate', $found['experience_level']);
        $this->assertEquals('available', $found['availability_status']);
        $this->assertEquals(4.5, $found['rating']);
        $this->assertEquals(10, $found['completed_jobs_count']);
        $this->assertEquals('Test Freelancer', $found['user']['name']);

        // Skills with pivot years are returned.
        $skillNames = collect($found['skills'])->pluck('name');
        $this->assertContains('Laravel', $skillNames);
        $this->assertContains('React', $skillNames);
        $this->assertEquals(3, $found['skills'][0]['years_of_experience']);
    }

    public function test_freelancer_list_does_not_expose_private_information(): void
    {
        $this->createFreelancer();

        $response = $this->getJson('/api/v1/freelancers');

        $response->assertStatus(200);
        $payload = $response->json('data');

        foreach ($payload as $item) {
            $this->assertArrayNotHasKey('email', $item);
            $this->assertArrayNotHasKey('password', $item);
            $this->assertArrayNotHasKey('token', $item);
            $this->assertArrayNotHasKey('total_earnings', $item);
            $this->assertArrayNotHasKey('github_url', $item);
            $this->assertArrayNotHasKey('linkedin_url', $item);
            $this->assertArrayNotHasKey('user_id', $item['user'] ?? []);
            $this->assertArrayNotHasKey('email', $item['user'] ?? []);
            $this->assertArrayNotHasKey('phone', $item['user'] ?? []);
        }
    }

    public function test_freelancers_are_paginated_with_meta(): void
    {
        foreach (range(1, 3) as $i) {
            $this->createFreelancer(['headline' => "Developer $i"]);
        }

        $response = $this->getJson('/api/v1/freelancers?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(2, $response->json('meta.per_page'));
        $this->assertEquals(3, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_freelancers_can_be_searched_by_name(): void
    {
        $this->createFreelancer(['name' => 'Abebe Tesfaye', 'headline' => 'Laravel Developer']);
        $this->createFreelancer(['name' => 'Meron Alemu', 'headline' => 'UI Designer']);

        $response = $this->getJson('/api/v1/freelancers?search=Abebe');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('user.name');
        $this->assertContains('Abebe Tesfaye', $names);
        $this->assertNotContains('Meron Alemu', $names);
    }

    public function test_freelancers_can_be_searched_by_headline_and_skills(): void
    {
        $this->createFreelancer(['headline' => 'React Specialist'], ['React']);
        $this->createFreelancer(['headline' => 'Data Analyst'], ['SQL']);

        // Search matches headline.
        $byHeadline = $this->getJson('/api/v1/freelancers?search=React');
        $byHeadline->assertStatus(200);
        $this->assertCount(1, $byHeadline->json('data'));
        $this->assertEquals('React Specialist', $byHeadline->json('data.0.headline'));

        // Search also matches skill names.
        $bySkill = $this->getJson('/api/v1/freelancers?search=SQL');
        $bySkill->assertStatus(200);
        $this->assertCount(1, $bySkill->json('data'));
        $this->assertEquals('Data Analyst', $bySkill->json('data.0.headline'));
    }

    public function test_freelancers_can_be_filtered_by_category(): void
    {
        $design = Category::create(['name' => 'Graphic Design', 'slug' => 'design-'.Str::random(4)]);
        $web = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);

        $designer = $this->createFreelancer(['headline' => 'Graphic Designer']);
        $designerSkill = Skill::create(['category_id' => $design->id, 'name' => 'Photoshop', 'slug' => 'ps-'.Str::random(4)]);
        $designer->skills()->attach($designerSkill->id, ['years_of_experience' => 4]);

        $developer = $this->createFreelancer(['headline' => 'Web Developer']);
        $devSkill = Skill::create(['category_id' => $web->id, 'name' => 'Laravel', 'slug' => 'laravel-'.Str::random(4)]);
        $developer->skills()->attach($devSkill->id, ['years_of_experience' => 4]);

        $response = $this->getJson('/api/v1/freelancers?category_id='.$design->id);

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertContains('Graphic Designer', $headlines);
        $this->assertNotContains('Web Developer', $headlines);
    }

    public function test_freelancers_can_be_filtered_by_skill(): void
    {
        $reactDev = $this->createFreelancer(['headline' => 'React Developer'], ['React']);
        $this->createFreelancer(['headline' => 'Python Developer'], ['Python']);

        $reactSkill = $reactDev->skills()->first();

        $response = $this->getJson('/api/v1/freelancers?skill_id='.$reactSkill->id);

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertContains('React Developer', $headlines);
        $this->assertNotContains('Python Developer', $headlines);
    }

    public function test_freelancers_can_be_filtered_by_experience_and_availability(): void
    {
        $this->createFreelancer(['experience_level' => 'expert', 'availability_status' => 'available']);
        $this->createFreelancer(['experience_level' => 'entry', 'availability_status' => 'busy']);

        $expert = $this->getJson('/api/v1/freelancers?experience_level=expert');
        $expert->assertStatus(200);
        $this->assertCount(1, $expert->json('data'));
        $this->assertEquals('expert', $expert->json('data.0.experience_level'));

        $busy = $this->getJson('/api/v1/freelancers?availability_status=busy');
        $busy->assertStatus(200);
        $this->assertCount(1, $busy->json('data'));
        $this->assertEquals('busy', $busy->json('data.0.availability_status'));

        // Invalid enum values are rejected with 422.
        $this->getJson('/api/v1/freelancers?experience_level=senior')->assertStatus(422);
        $this->getJson('/api/v1/freelancers?availability_status=maybe')->assertStatus(422);
    }

    public function test_freelancers_can_be_filtered_by_hourly_rate(): void
    {
        $this->createFreelancer(['headline' => 'Cheap Freelancer', 'hourly_rate' => 200.00]);
        $this->createFreelancer(['headline' => 'Mid Freelancer', 'hourly_rate' => 600.00]);
        $this->createFreelancer(['headline' => 'Expensive Freelancer', 'hourly_rate' => 1500.00]);

        $min = $this->getJson('/api/v1/freelancers?min_hourly_rate=500');
        $min->assertStatus(200);
        $headlines = collect($min->json('data'))->pluck('headline');
        $this->assertNotContains('Cheap Freelancer', $headlines);
        $this->assertContains('Mid Freelancer', $headlines);
        $this->assertContains('Expensive Freelancer', $headlines);

        $max = $this->getJson('/api/v1/freelancers?max_hourly_rate=700');
        $max->assertStatus(200);
        $headlines = collect($max->json('data'))->pluck('headline');
        $this->assertContains('Cheap Freelancer', $headlines);
        $this->assertContains('Mid Freelancer', $headlines);
        $this->assertNotContains('Expensive Freelancer', $headlines);
    }

    public function test_freelancers_can_be_sorted_by_rating(): void
    {
        $this->createFreelancer(['headline' => 'Low Rated', 'rating' => 2.0]);
        $this->createFreelancer(['headline' => 'High Rated', 'rating' => 5.0]);

        $response = $this->getJson('/api/v1/freelancers?sort=rating&direction=desc');

        $response->assertStatus(200);
        $headlines = collect($response->json('data'))->pluck('headline');
        $this->assertEquals('High Rated', $headlines->first());
        $this->assertEquals('Low Rated', $headlines->last());
    }

    public function test_single_public_freelancer_profile_still_works(): void
    {
        $profile = $this->createFreelancer(
            ['headline' => 'Senior Laravel Developer'],
            ['Laravel']
        );

        $this->getJson('/api/v1/freelancers/'.$profile->id)
            ->assertStatus(200)
            ->assertJsonPath('data.headline', 'Senior Laravel Developer');

        // Also accessible by user id (existing behavior).
        $this->getJson('/api/v1/freelancers/'.$profile->user_id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $profile->id);
    }
}
