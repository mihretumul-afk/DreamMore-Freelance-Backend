<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private User $otherFreelancer;
    private User $employer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $this->otherFreelancer = User::factory()->create([
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $this->employer = User::factory()->create([
            'role' => 'employer',
            'status' => 'active',
        ]);
    }

    public function test_freelancer_can_create_portfolio_item(): void
    {
        $category = Category::create(['name' => 'Web Development', 'slug' => 'web-development']);
        $skill = Skill::create(['name' => 'React', 'slug' => 'react', 'category_id' => $category->id]);

        $response = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/freelancer/portfolio', [
                'title' => 'E-Commerce Website',
                'description' => 'A modern online shop built with React and Laravel',
                'category_id' => $category->id,
                'skill_id' => $skill->id,
                'project_url' => 'https://example.com/ecommerce',
                'display_order' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'E-Commerce Website')
            ->assertJsonPath('data.user_id', $this->freelancer->id);

        $this->assertDatabaseHas('portfolio_items', [
            'user_id' => $this->freelancer->id,
            'title' => 'E-Commerce Website',
        ]);
    }

    public function test_freelancer_can_upload_portfolio_image(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('preview.png', 800, 600);

        $response = $this->actingAs($this->freelancer)
            ->postJson('/api/v1/freelancer/portfolio', [
                'title' => 'Mobile Banking App',
                'image' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $item = PortfolioItem::where('user_id', $this->freelancer->id)->first();
        $this->assertNotNull($item->image_url);
    }

    public function test_freelancer_can_list_own_portfolio_items(): void
    {
        PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Project A',
            'display_order' => 1,
        ]);

        PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Project B',
            'display_order' => 2,
        ]);

        PortfolioItem::create([
            'user_id' => $this->otherFreelancer->id,
            'title' => 'Other Project',
            'display_order' => 1,
        ]);

        $response = $this->actingAs($this->freelancer)
            ->getJson('/api/v1/freelancer/portfolio');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Project A')
            ->assertJsonPath('data.1.title', 'Project B');
    }

    public function test_freelancer_can_update_own_portfolio_item(): void
    {
        $item = PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Old Title',
            'description' => 'Old Description',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->putJson("/api/v1/freelancer/portfolio/{$item->id}", [
                'title' => 'New Title',
                'description' => 'Updated Description',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.description', 'Updated Description');
    }

    public function test_freelancer_cannot_update_others_portfolio_item(): void
    {
        $item = PortfolioItem::create([
            'user_id' => $this->otherFreelancer->id,
            'title' => 'Other Title',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->putJson("/api/v1/freelancer/portfolio/{$item->id}", [
                'title' => 'Hacked Title',
            ]);

        $response->assertStatus(403);
    }

    public function test_freelancer_can_delete_own_portfolio_item(): void
    {
        $item = PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'To Delete',
        ]);

        $response = $this->actingAs($this->freelancer)
            ->deleteJson("/api/v1/freelancer/portfolio/{$item->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
    }

    public function test_public_can_view_freelancer_portfolio(): void
    {
        PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Public Showcase',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}/portfolio");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Public Showcase');
    }

    public function test_reorder_portfolio_items(): void
    {
        $item1 = PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'First',
            'display_order' => 1,
        ]);

        $item2 = PortfolioItem::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Second',
            'display_order' => 2,
        ]);

        $response = $this->actingAs($this->freelancer)
            ->putJson('/api/v1/freelancer/portfolio-reorder', [
                'items' => [
                    ['id' => $item1->id, 'order' => 2],
                    ['id' => $item2->id, 'order' => 1],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertEquals(2, $item1->fresh()->display_order);
        $this->assertEquals(1, $item2->fresh()->display_order);
    }
}
