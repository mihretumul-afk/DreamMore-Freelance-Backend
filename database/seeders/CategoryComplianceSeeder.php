<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoryComplianceSeeder extends Seeder
{
    public function run(): void
    {
        $requiredCategories = [
            // Technology
            ['name' => 'Web Development', 'slug' => 'web-development', 'description' => 'Web application and site development'],
            ['name' => 'Mobile Development', 'slug' => 'mobile-development', 'description' => 'Mobile application development for iOS and Android'],
            ['name' => 'UI/UX Design', 'slug' => 'ui-ux-design', 'description' => 'User interface and user experience design'],

            // Creative
            ['name' => 'Video Editing', 'slug' => 'video-editing', 'description' => 'Video editing and post-production'],
            ['name' => 'Graphic Design', 'slug' => 'graphic-design', 'description' => 'Graphic design, branding, and visual media'],
            ['name' => 'Animation', 'slug' => 'animation', 'description' => '2D and 3D animation, motion graphics'],

            // Engineering
            ['name' => 'Mechanical Design', 'slug' => 'mechanical-design', 'description' => 'Mechanical engineering and product design'],
            ['name' => 'CAD Modeling', 'slug' => 'cad-modeling', 'description' => 'Computer-aided design and 3D modeling'],
            ['name' => 'Simulation', 'slug' => 'simulation', 'description' => 'Engineering simulations and analysis (FEA, CFD)'],

            // Business
            ['name' => 'Marketing', 'slug' => 'marketing', 'description' => 'Digital marketing, SEO, social media and campaigns'],
            ['name' => 'Data Entry', 'slug' => 'data-entry', 'description' => 'Data entry, processing, and management'],
            ['name' => 'Virtual Assistant', 'slug' => 'virtual-assistant', 'description' => 'Virtual assistance and administrative support'],
        ];

        foreach ($requiredCategories as $cat) {
            // Check if exact slug exists
            $bySlug = Category::where('slug', $cat['slug'])->first();
            if ($bySlug) {
                $bySlug->update([
                    'name' => $cat['name'],
                    'is_active' => true,
                ]);
                continue;
            }

            // Check if exact name exists
            $byName = Category::where('name', $cat['name'])->first();
            if ($byName) {
                $byName->update([
                    'slug' => $cat['slug'],
                    'is_active' => true,
                ]);
                continue;
            }

            // Otherwise create new
            Category::create([
                'name' => $cat['name'],
                'slug' => $cat['slug'],
                'description' => $cat['description'],
                'is_active' => true,
            ]);
        }
    }
}
