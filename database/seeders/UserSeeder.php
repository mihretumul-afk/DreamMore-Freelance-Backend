<?php

namespace Database\Seeders;

use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Shared development password for every seeded account.
     * Only used in local development; never logged or returned by the API.
     */
    public const DEV_PASSWORD = 'DreamMore@2026';

    /**
     * Seed admin + employer + freelancer accounts with their profiles.
     */
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedEmployers();
        $this->seedFreelancers();
    }

    private function seedAdmin(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@dreammore.com'],
            [
                'name' => 'Dream More Admin',
                'password' => Hash::make(self::DEV_PASSWORD),
                'role' => 'admin',
                'status' => 'active',
                'avatar' => null,
            ]
        );
    }

    /**
     * @return array<int, array{name: string, email: string, company: string, industry: string, location: string}>
     */
    private function employerDefinitions(): array
    {
        return [
            [
                'name' => 'Selam Tech Solutions',
                'email' => 'selamtech@dreammore.com',
                'company' => 'Selam Tech Solutions PLC',
                'industry' => 'Software & IT Services',
                'location' => 'Addis Ababa',
            ],
            [
                'name' => 'Habesha Digital Agency',
                'email' => 'habesha.digital@dreammore.com',
                'company' => 'Habesha Digital Agency',
                'industry' => 'Digital Marketing',
                'location' => 'Addis Ababa',
            ],
            [
                'name' => 'Nile Software Works',
                'email' => 'nile.software@dreammore.com',
                'company' => 'Nile Software Works',
                'industry' => 'Web & Mobile Development',
                'location' => 'Bahir Dar',
            ],
            [
                'name' => 'Addis Creative Studio',
                'email' => 'addis.creative@dreammore.com',
                'company' => 'Addis Creative Studio',
                'industry' => 'Design & Branding',
                'location' => 'Addis Ababa',
            ],
            [
                'name' => 'EthioEcommerce Hub',
                'email' => 'ethio.ecommerce@dreammore.com',
                'company' => 'EthioEcommerce Hub',
                'industry' => 'E-Commerce & Retail',
                'location' => 'Hawassa',
            ],
            [
                'name' => 'Axum Data Labs',
                'email' => 'axum.data@dreammore.com',
                'company' => 'Axum Data Labs',
                'industry' => 'Data & AI Services',
                'location' => 'Mekelle',
            ],
        ];
    }

    private function seedEmployers(): void
    {
        foreach ($this->employerDefinitions() as $definition) {
            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make(self::DEV_PASSWORD),
                    'role' => 'employer',
                    'status' => 'active',
                ]
            );

            EmployerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_name' => $definition['company'],
                    'company_description' => "{$definition['company']} hires Ethiopian freelancers through Dream More AppWorks.",
                    'industry' => $definition['industry'],
                    'company_size' => 'small',
                    'location' => $definition['location'],
                    'website' => null,
                ]
            );
        }
    }

    /**
     * @return array<int, array{
     *     name: string, email: string, headline: string, overview: string,
     *     hourly_rate: float, experience_level: string, location: string,
     *     availability_status: string, skills: array<int, array{name: string, years: int}>
     * }>
     */
    private function freelancerDefinitions(): array
    {
        return [
            [
                'name' => 'Abebe Tesfaye',
                'email' => 'abebe.tesfaye@dreammore.com',
                'headline' => 'Senior Full-Stack Developer (Laravel & React)',
                'overview' => '8+ years building web applications for Ethiopian and international clients. I specialize in Laravel APIs, React dashboards and scalable database design.',
                'hourly_rate' => 1200.00,
                'experience_level' => 'expert',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Laravel', 'years' => 8],
                    ['name' => 'React', 'years' => 6],
                    ['name' => 'PHP', 'years' => 8],
                    ['name' => 'REST API', 'years' => 7],
                    ['name' => 'MySQL', 'years' => 7],
                ],
            ],
            [
                'name' => 'Meron Alemu',
                'email' => 'meron.alemu@dreammore.com',
                'headline' => 'UI/UX Designer & Brand Identity Specialist',
                'overview' => 'I design clean, user-friendly interfaces and complete brand systems — from wireframes to polished design systems in Figma.',
                'hourly_rate' => 950.00,
                'experience_level' => 'expert',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Figma', 'years' => 6],
                    ['name' => 'UI Design', 'years' => 5],
                    ['name' => 'Branding', 'years' => 4],
                    ['name' => 'Design Systems', 'years' => 3],
                    ['name' => 'Prototyping', 'years' => 5],
                ],
            ],
            [
                'name' => 'Dawit Bekele',
                'email' => 'dawit.bekele@dreammore.com',
                'headline' => 'Mobile App Developer — Flutter & React Native',
                'overview' => 'Cross-platform mobile apps that perform beautifully on Android and iOS. I have shipped 15+ apps for fintech, e-commerce and logistics clients.',
                'hourly_rate' => 1000.00,
                'experience_level' => 'expert',
                'location' => 'Bahir Dar',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Flutter', 'years' => 5],
                    ['name' => 'React Native', 'years' => 4],
                    ['name' => 'Android', 'years' => 5],
                    ['name' => 'REST API', 'years' => 5],
                ],
            ],
            [
                'name' => 'Hanna Girma',
                'email' => 'hanna.girma@dreammore.com',
                'headline' => 'Content Writer & Amharic-English Translator',
                'overview' => 'Bilingual writer producing blog posts, website copy, product descriptions and accurate Amharic-English translations.',
                'hourly_rate' => 500.00,
                'experience_level' => 'intermediate',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'English Writing', 'years' => 6],
                    ['name' => 'Amharic Writing', 'years' => 6],
                    ['name' => 'Amharic Translation', 'years' => 5],
                    ['name' => 'Copywriting', 'years' => 4],
                ],
            ],
            [
                'name' => 'Yonas Haile',
                'email' => 'yonas.haile@dreammore.com',
                'headline' => 'Video Editor & Motion Graphics Artist',
                'overview' => 'YouTube, social media and commercial video editing with After Effects motion graphics. Quick turnaround and reliable delivery.',
                'hourly_rate' => 750.00,
                'experience_level' => 'intermediate',
                'location' => 'Addis Ababa',
                'availability_status' => 'busy',
                'skills' => [
                    ['name' => 'Adobe Premiere Pro', 'years' => 5],
                    ['name' => 'After Effects', 'years' => 4],
                    ['name' => 'Motion Graphics', 'years' => 3],
                    ['name' => 'DaVinci Resolve', 'years' => 2],
                ],
            ],
            [
                'name' => 'Sara Mohammed',
                'email' => 'sara.mohammed@dreammore.com',
                'headline' => 'Digital Marketer & Social Media Manager',
                'overview' => 'I grow brands with Meta/Google ad campaigns, email marketing and content calendars that convert followers into customers.',
                'hourly_rate' => 850.00,
                'experience_level' => 'expert',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Google Ads', 'years' => 4],
                    ['name' => 'Meta Ads', 'years' => 4],
                    ['name' => 'Email Marketing', 'years' => 5],
                    ['name' => 'Social Media Marketing', 'years' => 5],
                ],
            ],
            [
                'name' => 'Kaleb Assefa',
                'email' => 'kaleb.assefa@dreammore.com',
                'headline' => 'Data Analyst & BI Developer',
                'overview' => 'Turning messy datasets into dashboards and decisions with Excel, Power BI and SQL. Strong experience in the banking and microfinance sectors.',
                'hourly_rate' => 800.00,
                'experience_level' => 'intermediate',
                'location' => 'Hawassa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Excel Analysis', 'years' => 5],
                    ['name' => 'Power BI', 'years' => 3],
                    ['name' => 'SQL', 'years' => 4],
                    ['name' => 'Data Visualization', 'years' => 3],
                ],
            ],
            [
                'name' => 'Liya Tadesse',
                'email' => 'liya.tadesse@dreammore.com',
                'headline' => 'AI Trainer & Data Annotation Specialist',
                'overview' => 'I train and evaluate LLMs and vision models with high-quality, well-documented datasets. Detail-oriented and prompt with deadlines.',
                'hourly_rate' => 600.00,
                'experience_level' => 'intermediate',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'AI Training', 'years' => 3],
                    ['name' => 'Data Annotation', 'years' => 3],
                    ['name' => 'Prompt Engineering', 'years' => 2],
                    ['name' => 'Model Evaluation', 'years' => 2],
                ],
            ],
            [
                'name' => 'Natnael Girma',
                'email' => 'natnael.girma@dreammore.com',
                'headline' => 'Graphic Designer & Logo Specialist',
                'overview' => 'Memorable logos, brand kits, flyers and social media graphics. I work with Photoshop, Illustrator and Canva.',
                'hourly_rate' => 450.00,
                'experience_level' => 'entry',
                'location' => 'Dire Dawa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Photoshop', 'years' => 3],
                    ['name' => 'Illustrator', 'years' => 2],
                    ['name' => 'Logo Design', 'years' => 2],
                    ['name' => 'Canva', 'years' => 3],
                ],
            ],
            [
                'name' => 'Bethelehem Fikru',
                'email' => 'bethelehem.fikru@dreammore.com',
                'headline' => 'Virtual Assistant & Admin Support Pro',
                'overview' => 'Organized virtual assistant for email management, calendar coordination, data entry and customer support. Reliable, responsive and detail-oriented.',
                'hourly_rate' => 350.00,
                'experience_level' => 'intermediate',
                'location' => 'Adama',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Administrative Support', 'years' => 4],
                    ['name' => 'Email Management', 'years' => 3],
                    ['name' => 'Calendar Management', 'years' => 3],
                    ['name' => 'Data Entry', 'years' => 4],
                ],
            ],
            [
                'name' => 'Eyob Getachew',
                'email' => 'eyob.getachew@dreammore.com',
                'headline' => 'Cybersecurity Analyst & Ethical Hacker',
                'overview' => 'Penetration testing, network security audits and Linux hardening for SMEs. Certified in CEH fundamentals and passionate about safe systems.',
                'hourly_rate' => 1100.00,
                'experience_level' => 'expert',
                'location' => 'Addis Ababa',
                'availability_status' => 'busy',
                'skills' => [
                    ['name' => 'Network Security', 'years' => 5],
                    ['name' => 'Ethical Hacking', 'years' => 4],
                    ['name' => 'Penetration Testing', 'years' => 3],
                    ['name' => 'Linux Security', 'years' => 4],
                ],
            ],
            [
                'name' => 'Ruth Daniel',
                'email' => 'ruth.daniel@dreammore.com',
                'headline' => 'Photographer & Photo Editor',
                'overview' => 'Product, portrait and event photography with professional retouching. Available for on-location shoots in Addis Ababa and remote editing.',
                'hourly_rate' => 700.00,
                'experience_level' => 'intermediate',
                'location' => 'Addis Ababa',
                'availability_status' => 'available',
                'skills' => [
                    ['name' => 'Product Photography', 'years' => 4],
                    ['name' => 'Portrait Photography', 'years' => 4],
                    ['name' => 'Photo Editing', 'years' => 5],
                    ['name' => 'Lightroom', 'years' => 3],
                ],
            ],
        ];
    }

    private function seedFreelancers(): void
    {
        foreach ($this->freelancerDefinitions() as $definition) {
            $skills = $definition['skills'];
            $name = $definition['name'];
            $email = $definition['email'];
            unset($definition['skills'], $definition['name'], $definition['email']);

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::DEV_PASSWORD),
                    'role' => 'freelancer',
                    'status' => 'active',
                ]
            );

            $profile = FreelancerProfile::updateOrCreate(
                ['user_id' => $user->id],
                $definition
            );

            // Attach skills through the freelancer_skills pivot and remember the
            // first category found so the profile itself gets a category_id
            // (used by the public /freelancers?category_id=... filter).
            $syncData = [];
            $profileCategoryId = $profile->category_id;
            foreach ($skills as $skillDefinition) {
                $skill = Skill::where('name', $skillDefinition['name'])->first();
                if ($skill) {
                    $syncData[$skill->id] = ['years_of_experience' => $skillDefinition['years']];

                    if (! $profileCategoryId && $skill->category_id) {
                        $profileCategoryId = $skill->category_id;
                    }
                }
            }
            $profile->skills()->sync($syncData);

            if (! $profile->category_id && $profileCategoryId) {
                $profile->update(['category_id' => $profileCategoryId]);
            }
        }
    }
}
