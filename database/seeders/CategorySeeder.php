<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * The Dream More category catalog: name, slug, short description, Lucide
     * icon name (mirrors the frontend catalog) and the skills in that category.
     *
     * @var array<int, array{name: string, slug: string, description: string, icon: string, skills: array<int, string>}>
     */
    private const CATEGORIES = [
        [
            'name' => 'Web Development',
            'slug' => 'web-development',
            'description' => 'Build fast, modern websites and web applications.',
            'icon' => 'Code2',
            'skills' => ['HTML', 'CSS', 'JavaScript', 'React', 'Vue', 'Angular', 'Node.js', 'PHP', 'Laravel', 'Python', 'Django', 'REST API', 'MySQL', 'PostgreSQL'],
        ],
        [
            'name' => 'Mobile App Development',
            'slug' => 'mobile-app-development',
            'description' => 'Create native and cross-platform mobile applications.',
            'icon' => 'Smartphone',
            'skills' => ['Flutter', 'React Native', 'Kotlin', 'Swift', 'Java', 'Android', 'iOS'],
        ],
        [
            'name' => 'UI/UX Design',
            'slug' => 'ui-ux-design',
            'description' => 'Design intuitive, beautiful and user-friendly interfaces.',
            'icon' => 'Layers',
            'skills' => ['Figma', 'Wireframing', 'Prototyping', 'User Research', 'Design Systems', 'Usability Testing'],
        ],
        [
            'name' => 'Graphic Design',
            'slug' => 'graphic-design',
            'description' => 'Eye-catching logos, branding and visual assets.',
            'icon' => 'Palette',
            'skills' => ['Photoshop', 'Illustrator', 'Figma', 'Canva', 'Logo Design', 'Branding', 'UI Design'],
        ],
        [
            'name' => 'Video Editing',
            'slug' => 'video-editing',
            'description' => 'Professional video editing and post-production.',
            'icon' => 'Clapperboard',
            'skills' => ['Adobe Premiere Pro', 'After Effects', 'DaVinci Resolve', 'CapCut', 'Motion Graphics', 'Video Production'],
        ],
        [
            'name' => 'Content Creation',
            'slug' => 'content-creation',
            'description' => 'Engaging content for YouTube, TikTok and social media.',
            'icon' => 'PenTool',
            'skills' => ['YouTube', 'TikTok', 'Instagram', 'Copywriting', 'Script Writing', 'Content Strategy'],
        ],
        [
            'name' => 'Digital Marketing',
            'slug' => 'digital-marketing',
            'description' => 'Grow your brand with data-driven marketing campaigns.',
            'icon' => 'Megaphone',
            'skills' => ['SEO', 'Google Ads', 'Meta Ads', 'Email Marketing', 'Social Media Marketing'],
        ],
        [
            'name' => 'SEO',
            'slug' => 'seo',
            'description' => 'Rank higher and get found by the right customers.',
            'icon' => 'Search',
            'skills' => ['On-Page SEO', 'Off-Page SEO', 'Technical SEO', 'Keyword Research', 'Link Building', 'Google Analytics'],
        ],
        [
            'name' => 'Social Media Management',
            'slug' => 'social-media-management',
            'description' => 'Plan, post and grow your social presence.',
            'icon' => 'Share2',
            'skills' => ['Facebook Marketing', 'Instagram Marketing', 'LinkedIn Marketing', 'TikTok Marketing', 'Community Management'],
        ],
        [
            'name' => 'Data Entry',
            'slug' => 'data-entry',
            'description' => 'Accurate data entry, cleaning and spreadsheet work.',
            'icon' => 'Keyboard',
            'skills' => ['Typing', 'Microsoft Excel', 'Google Sheets', 'Data Entry', 'Data Cleaning'],
        ],
        [
            'name' => 'Data Analysis',
            'slug' => 'data-analysis',
            'description' => 'Turn raw data into clear, actionable insights.',
            'icon' => 'BarChart3',
            'skills' => ['Excel Analysis', 'Power BI', 'Tableau', 'SQL', 'Data Visualization'],
        ],
        [
            'name' => 'AI & Machine Learning',
            'slug' => 'ai-machine-learning',
            'description' => 'Intelligent systems, models and AI-powered products.',
            'icon' => 'BrainCircuit',
            'skills' => ['Machine Learning', 'Deep Learning', 'Python AI', 'TensorFlow', 'PyTorch', 'Generative AI', 'Prompt Engineering'],
        ],
        [
            'name' => 'AI Training',
            'slug' => 'ai-training',
            'description' => 'Train, evaluate and improve AI models with quality data.',
            'icon' => 'Bot',
            'skills' => ['AI Training', 'Data Annotation', 'Prompt Engineering', 'Model Evaluation'],
        ],
        [
            'name' => 'Software Development',
            'slug' => 'software-development',
            'description' => 'Custom software, APIs and enterprise solutions.',
            'icon' => 'Terminal',
            'skills' => ['Java', 'C#', '.NET', 'Go', 'Rust', 'DevOps', 'Git', 'API Development'],
        ],
        [
            'name' => 'Writing & Translation',
            'slug' => 'writing-translation',
            'description' => 'Clear writing and accurate translation in multiple languages.',
            'icon' => 'Languages',
            'skills' => ['English Writing', 'Amharic Writing', 'Amharic Translation', 'Proofreading', 'Technical Writing', 'Editing'],
        ],
        [
            'name' => 'Virtual Assistance',
            'slug' => 'virtual-assistance',
            'description' => 'Reliable administrative and operational support.',
            'icon' => 'Headset',
            'skills' => ['Administrative Support', 'Email Management', 'Calendar Management', 'Customer Support', 'Data Entry'],
        ],
        [
            'name' => 'Photography',
            'slug' => 'photography',
            'description' => 'Professional photography for every occasion.',
            'icon' => 'Camera',
            'skills' => ['Portrait Photography', 'Product Photography', 'Event Photography', 'Photo Editing', 'Drone Photography', 'Lightroom'],
        ],
        [
            'name' => 'Animation',
            'slug' => 'animation',
            'description' => '2D, 3D and motion design that brings ideas to life.',
            'icon' => 'Sparkles',
            'skills' => ['2D Animation', '3D Animation', 'Character Animation', 'Storyboarding', 'Explainer Videos'],
        ],
        [
            'name' => 'Cybersecurity',
            'slug' => 'cybersecurity',
            'description' => 'Protect systems, networks and data from threats.',
            'icon' => 'ShieldCheck',
            'skills' => ['Network Security', 'Ethical Hacking', 'Linux Security', 'Penetration Testing'],
        ],
        [
            'name' => 'Other',
            'slug' => 'other',
            'description' => 'Anything else you need done well.',
            'icon' => 'MoreHorizontal',
            'skills' => ['Consulting', 'Project Management', 'Training'],
        ],
    ];

    /**
     * Seed categories and their skills idempotently (keyed on slug).
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $categoryData) {
            $skills = $categoryData['skills'];
            unset($categoryData['skills']);

            $category = Category::updateOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );

            foreach ($skills as $skillName) {
                Skill::updateOrCreate(
                    ['slug' => Str::slug($skillName)],
                    [
                        'category_id' => $category->id,
                        'name' => $skillName,
                    ]
                );
            }
        }
    }
}
