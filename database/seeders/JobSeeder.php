<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Job;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class JobSeeder extends Seeder
{
    /**
     * Realistic open jobs: employer email, category slug, title, description,
     * budget type, min/max budget (ETB), experience level, location type,
     * location, deadline offset in days and the skills the job requires.
     *
     * @var array<int, array<string, mixed>>
     */
    private const JOBS = [
        [
            'employer' => 'nile.software@dreammore.com',
            'category' => 'web-development',
            'title' => 'React Developer for E-Commerce Platform',
            'description' => 'We are building an Ethiopian e-commerce platform and need a React developer to build responsive storefront pages, integrate REST APIs and work closely with our Laravel backend team.',
            'budget_type' => 'fixed',
            'min_budget' => 60000,
            'max_budget' => 120000,
            'experience_level' => 'expert',
            'location_type' => 'remote',
            'location' => 'Addis Ababa',
            'deadline_days' => 21,
            'skills' => ['React', 'JavaScript', 'REST API', 'CSS'],
        ],
        [
            'employer' => 'nile.software@dreammore.com',
            'category' => 'web-development',
            'title' => 'Laravel API Developer',
            'description' => 'Looking for a Laravel developer to design and build REST APIs, manage MySQL schemas and ship clean, testable code for a fintech product.',
            'budget_type' => 'hourly',
            'min_budget' => 800,
            'max_budget' => 1500,
            'experience_level' => 'expert',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 30,
            'skills' => ['Laravel', 'PHP', 'MySQL', 'REST API'],
        ],
        [
            'employer' => 'addis.creative@dreammore.com',
            'category' => 'graphic-design',
            'title' => 'Logo & Brand Identity Designer',
            'description' => 'We need a complete brand identity for a new Ethiopian coffee brand: logo, color palette, typography guide and social media templates.',
            'budget_type' => 'fixed',
            'min_budget' => 15000,
            'max_budget' => 35000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => 'Addis Ababa',
            'deadline_days' => 14,
            'skills' => ['Logo Design', 'Branding', 'Illustrator', 'Figma'],
        ],
        [
            'employer' => 'habesha.digital@dreammore.com',
            'category' => 'video-editing',
            'title' => 'YouTube Video Editor (Weekly)',
            'description' => 'Edit 2 long-form YouTube videos per week for a growing Ethiopian tech channel: cuts, captions, thumbnails and motion graphics.',
            'budget_type' => 'hourly',
            'min_budget' => 400,
            'max_budget' => 900,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 10,
            'skills' => ['Adobe Premiere Pro', 'After Effects', 'Motion Graphics'],
        ],
        [
            'employer' => 'habesha.digital@dreammore.com',
            'category' => 'content-creation',
            'title' => 'Social Media Content Creator',
            'description' => 'Create short-form video and static content for Instagram and TikTok for a lifestyle brand. Must be fluent in Amharic and English.',
            'budget_type' => 'fixed',
            'min_budget' => 20000,
            'max_budget' => 40000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => 'Addis Ababa',
            'deadline_days' => 30,
            'skills' => ['TikTok', 'Instagram', 'Copywriting', 'Script Writing'],
        ],
        
        [
            'employer' => 'axum.data@dreammore.com',
            'category' => 'data-analysis',
            'title' => 'Market Research Data Analyst',
            'description' => 'Analyze survey data for a market-entry study, clean the dataset, build a Power BI dashboard and summarize key findings in a report.',
            'budget_type' => 'fixed',
            'min_budget' => 30000,
            'max_budget' => 55000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => 'Addis Ababa',
            'deadline_days' => 18,
            'skills' => ['Excel Analysis', 'Power BI', 'SQL', 'Data Visualization'],
        ],
        [
            'employer' => 'selamtech@dreammore.com',
            'category' => 'mobile-app-development',
            'title' => 'Flutter Developer for Ride-Hailing App',
            'description' => 'Build the rider-facing Flutter app for a new Ethiopian ride-hailing service: maps integration, payments UI and real-time tracking.',
            'budget_type' => 'fixed',
            'min_budget' => 150000,
            'max_budget' => 250000,
            'experience_level' => 'expert',
            'location_type' => 'onsite',
            'location' => 'Addis Ababa',
            'deadline_days' => 45,
            'skills' => ['Flutter', 'Android', 'REST API'],
        ],
        [
            'employer' => 'selamtech@dreammore.com',
            'category' => 'ui-ux-design',
            'title' => 'UI/UX Designer for Mobile App',
            'description' => 'Design the complete mobile app UX for our ride-hailing product — user flows, wireframes, high-fidelity screens and a Figma design system.',
            'budget_type' => 'fixed',
            'min_budget' => 45000,
            'max_budget' => 80000,
            'experience_level' => 'expert',
            'location_type' => 'hybrid',
            'location' => 'Addis Ababa',
            'deadline_days' => 25,
            'skills' => ['Figma', 'Wireframing', 'Prototyping', 'Design Systems'],
        ],
        [
            'employer' => 'ethio.ecommerce@dreammore.com',
            'category' => 'seo',
            'title' => 'SEO Specialist for Online Store',
            'description' => 'Improve organic rankings for our e-commerce site: keyword research, on-page optimization, technical SEO fixes and monthly reporting.',
            'budget_type' => 'hourly',
            'min_budget' => 500,
            'max_budget' => 1000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 60,
            'skills' => ['SEO', 'On-Page SEO', 'Technical SEO', 'Keyword Research'],
        ],
        [
            'employer' => 'ethio.ecommerce@dreammore.com',
            'category' => 'writing-translation',
            'title' => 'Product Description Writer (Amharic & English)',
            'description' => 'Write compelling, SEO-friendly product descriptions for 300+ products in both Amharic and English for our online store.',
            'budget_type' => 'fixed',
            'min_budget' => 15000,
            'max_budget' => 25000,
            'experience_level' => 'entry',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 20,
            'skills' => ['English Writing', 'Amharic Writing', 'Copywriting'],
        ],
        [
            'employer' => 'selamtech@dreammore.com',
            'category' => 'virtual-assistance',
            'title' => 'Executive Virtual Assistant',
            'description' => 'Support our CEO with email management, meeting scheduling, travel booking and light data entry. English and Amharic required.',
            'budget_type' => 'hourly',
            'min_budget' => 250,
            'max_budget' => 450,
            'experience_level' => 'entry',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 7,
            'skills' => ['Administrative Support', 'Email Management', 'Calendar Management'],
        ],
        [
            'employer' => 'axum.data@dreammore.com',
            'category' => 'ai-machine-learning',
            'title' => 'Machine Learning Engineer (NLP)',
            'description' => 'Build and fine-tune NLP models for Amharic text classification. Strong Python and PyTorch experience required.',
            'budget_type' => 'fixed',
            'min_budget' => 180000,
            'max_budget' => 300000,
            'experience_level' => 'expert',
            'location_type' => 'hybrid',
            'location' => 'Addis Ababa',
            'deadline_days' => 40,
            'skills' => ['Machine Learning', 'Python AI', 'PyTorch', 'Deep Learning'],
        ],
        [
            'employer' => 'habesha.digital@dreammore.com',
            'category' => 'social-media-management',
            'title' => 'Social Media Manager for Agency Clients',
            'description' => 'Manage Instagram, Facebook and TikTok accounts for 3 clients: content calendar, posting, community engagement and monthly analytics.',
            'budget_type' => 'fixed',
            'min_budget' => 18000,
            'max_budget' => 30000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 30,
            'skills' => ['Instagram Marketing', 'Facebook Marketing', 'Community Management'],
        ],
        [
            'employer' => 'addis.creative@dreammore.com',
            'category' => 'photography',
            'title' => 'Product Photographer for Handicraft Store',
            'description' => 'Shoot high-quality product photos for an Ethiopian handicraft e-commerce store: 80 products, white background, retouching included.',
            'budget_type' => 'fixed',
            'min_budget' => 25000,
            'max_budget' => 40000,
            'experience_level' => 'intermediate',
            'location_type' => 'onsite',
            'location' => 'Addis Ababa',
            'deadline_days' => 15,
            'skills' => ['Product Photography', 'Photo Editing', 'Lightroom'],
        ],
        [
            'employer' => 'selamtech@dreammore.com',
            'category' => 'cybersecurity',
            'title' => 'Penetration Testing of Web Application',
            'description' => 'Perform a full security assessment of our Laravel web application: OWASP Top 10 testing, vulnerability report and remediation guidance.',
            'budget_type' => 'fixed',
            'min_budget' => 80000,
            'max_budget' => 120000,
            'experience_level' => 'expert',
            'location_type' => 'remote',
            'location' => null,
            'deadline_days' => 21,
            'skills' => ['Penetration Testing', 'Network Security', 'Ethical Hacking'],
        ],
    ];

    /**
     * Seed open jobs idempotently (keyed on slug) with their skills.
     */
    public function run(): void
    {
        foreach (self::JOBS as $jobData) {
            $employer = User::where('email', $jobData['employer'])->first();
            $category = Category::where('slug', $jobData['category'])->first();

            if (! $employer || ! $category) {
                continue;
            }

            $skills = $jobData['skills'];
            $deadlineDays = $jobData['deadline_days'];
            unset($jobData['employer'], $jobData['category'], $jobData['skills'], $jobData['deadline_days']);

            $job = Job::updateOrCreate(
                ['slug' => Str::slug($jobData['title'])],
                array_merge($jobData, [
                    'employer_id' => $employer->id,
                    'category_id' => $category->id,
                    'currency' => 'ETB',
                    'status' => 'open',
                    'proposals_count' => 0,
                    'deadline' => now()->addDays($deadlineDays),
                    'published_at' => now()->subDays(random_int(1, 10)),
                ])
            );

            // Attach the skills through the job_skills pivot.
            $skillIds = Skill::whereIn('name', $skills)->pluck('id')->all();
            if (! empty($skillIds)) {
                $job->skills()->sync($skillIds);
            }
        }
    }
}
