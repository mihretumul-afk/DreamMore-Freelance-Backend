<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SkillTest extends TestCase
{
    use RefreshDatabase;

    private function createSkill(Category $category, string $name): Skill
    {
        return Skill::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
        ]);
    }

    public function test_skills_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/skills')->assertStatus(200);
    }

    public function test_skills_are_returned_with_category_info(): void
    {
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-dev-'.Str::random(4)]);
        $skill = $this->createSkill($category, 'Laravel');

        $response = $this->getJson('/api/v1/skills');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $skills = collect($response->json('data'));
        $found = $skills->firstWhere('id', $skill->id);

        $this->assertNotNull($found);
        $this->assertEquals('Laravel', $found['name']);
        $this->assertEquals($category->id, $found['category_id']);
        $this->assertEquals('Web Development', $found['category']['name']);
        $this->assertEquals($category->slug, $found['category']['slug']);
    }

    public function test_skills_can_be_filtered_by_category(): void
    {
        $web = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
        $design = Category::create(['name' => 'Graphic Design', 'slug' => 'design-'.Str::random(4)]);

        $this->createSkill($web, 'React');
        $this->createSkill($web, 'Laravel');
        $this->createSkill($design, 'Photoshop');

        $response = $this->getJson('/api/v1/skills?category_id='.$web->id);

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('React', $names);
        $this->assertContains('Laravel', $names);
        $this->assertNotContains('Photoshop', $names);
    }

    public function test_skills_can_be_searched_by_name(): void
    {
        $web = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
        $this->createSkill($web, 'Laravel');
        $this->createSkill($web, 'Flutter');

        $response = $this->getJson('/api/v1/skills?search=Laravel');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Laravel', $names);
        $this->assertNotContains('Flutter', $names);
    }

    public function test_invalid_category_filter_is_handled_gracefully(): void
    {
        Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);

        $response = $this->getJson('/api/v1/skills?category_id=999999');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_inactive_skills_are_hidden(): void
    {
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-'.Str::random(4)]);
        $this->createSkill($category, 'Visible Skill');
        Skill::create([
            'category_id' => $category->id,
            'name' => 'Hidden Skill',
            'slug' => 'hidden-'.Str::random(4),
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/skills');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Visible Skill', $names);
        $this->assertNotContains('Hidden Skill', $names);
    }
}
