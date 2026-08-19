<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Job;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a category with a skill (and optionally an open job for counts).
     */
    private function createCategory(string $name, bool $active = true): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => "$name category description.",
            'icon' => 'Code2',
            'is_active' => $active,
        ]);
    }

    public function test_categories_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/categories')->assertStatus(200);
    }

    public function test_categories_are_returned_with_skills_and_counts(): void
    {
        $category = $this->createCategory('Web Development');
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-'.Str::random(4), 'category_id' => $category->id]);
        Skill::create(['name' => 'React', 'slug' => 'react-'.Str::random(4), 'category_id' => $category->id]);

        $employer = User::factory()->create(['role' => 'employer', 'status' => 'active']);
        Job::create([
            'employer_id' => $employer->id,
            'category_id' => $category->id,
            'title' => 'Laravel Developer Needed',
            'slug' => 'job-'.Str::random(8),
            'description' => 'Build APIs with Laravel.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 60000,
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categories retrieved successfully.');

        $categories = collect($response->json('data'));
        $found = $categories->firstWhere('id', $category->id);

        $this->assertNotNull($found);
        $this->assertEquals('Web Development', $found['name']);
        $this->assertEquals(2, $found['skills_count']);
        $this->assertEquals(1, $found['jobs_count']);
        $this->assertCount(2, $found['skills']);
        $this->assertEquals('Laravel', $found['skills'][0]['name']);
    }

    public function test_inactive_categories_are_hidden(): void
    {
        $this->createCategory('Visible Category', true);
        $this->createCategory('Hidden Category', false);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Visible Category', $names);
        $this->assertNotContains('Hidden Category', $names);
    }

    public function test_categories_can_be_searched_by_name(): void
    {
        $this->createCategory('Graphic Design');
        $this->createCategory('Web Development');

        $response = $this->getJson('/api/v1/categories?search=Graphic');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Graphic Design', $names);
        $this->assertNotContains('Web Development', $names);
    }
}
