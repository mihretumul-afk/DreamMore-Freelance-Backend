<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use Database\Seeders\CategoryComplianceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_appworks_categories_exist_and_are_active(): void
    {
        $this->seed(CategoryComplianceSeeder::class);

        $required = [
            // Technology
            'Web Development',
            'Mobile Development',
            'UI/UX Design',

            // Creative
            'Video Editing',
            'Graphic Design',
            'Animation',

            // Engineering
            'Mechanical Design',
            'CAD Modeling',
            'Simulation',

            // Business
            'Marketing',
            'Data Entry',
            'Virtual Assistant',
        ];

        foreach ($required as $categoryName) {
            $category = Category::where('name', $categoryName)->first();
            $this->assertNotNull($category, "Category '{$categoryName}' must exist.");
            $this->assertTrue((bool) $category->is_active, "Category '{$categoryName}' must be active.");
        }
    }
}
