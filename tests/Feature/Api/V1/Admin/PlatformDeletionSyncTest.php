<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\SavedFreelancer;
use App\Models\SavedJob;
use App\Models\Skill;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformDeletionSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
    }

    private function actingAsSanctum(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function createFreelancer(string $name, float $rating = 4.5, array $skills = []): array
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name) . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'freelancer',
            'status' => 'active',
        ]);

        $profile = FreelancerProfile::create([
            'user_id' => $user->id,
            'headline' => "Expert {$name}",
            'overview' => "Overview for {$name}",
            'hourly_rate' => 600.00,
            'experience_level' => 'expert',
            'location' => 'Addis Ababa',
            'availability_status' => 'available',
            'rating' => $rating,
            'completed_jobs_count' => 5,
        ]);

        if (! empty($skills)) {
            $cat = Category::firstOrCreate(
                ['slug' => 'tech'],
                ['name' => 'Technology', 'is_active' => true]
            );
            $syncData = [];
            foreach ($skills as $skillName) {
                $skill = Skill::firstOrCreate(
                    ['slug' => Str::slug($skillName)],
                    ['name' => $skillName, 'category_id' => $cat->id]
                );
                $syncData[$skill->id] = ['years_of_experience' => 3];
            }
            $profile->skills()->sync($syncData);
        }

        return ['user' => $user, 'profile' => $profile];
    }

    private function createEmployer(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::slug($name) . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'employer',
            'status' => 'active',
        ]);
    }

    private function createJob(User $employer, string $title, ?int $categoryId = null, array $skillNames = []): Job
    {
        $job = Job::create([
            'employer_id' => $employer->id,
            'category_id' => $categoryId,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'description' => "Job description for {$title}",
            'budget_type' => 'fixed',
            'min_budget' => 10000,
            'max_budget' => 25000,
            'experience_level' => 'intermediate',
            'location_type' => 'remote',
            'status' => 'open',
            'published_at' => now(),
        ]);

        if (! empty($skillNames)) {
            $cat = $categoryId ? Category::find($categoryId) : Category::firstOrCreate(['slug' => 'tech'], ['name' => 'Tech', 'is_active' => true]);
            $skillIds = [];
            foreach ($skillNames as $name) {
                $s = Skill::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'category_id' => $cat->id]);
                $skillIds[] = $s->id;
            }
            $job->skills()->sync($skillIds);
        }

        return $job;
    }

    // ─── 1. FREELANCER DELETION SYNCHRONIZATION ─────────────────────────

    public function test_admin_deleting_freelancer_removes_them_and_replaces_with_active_freelancers(): void
    {
        $f1 = $this->createFreelancer('Freelancer Alpha', 5.0);
        $f2 = $this->createFreelancer('Freelancer Beta', 4.8);
        $f3 = $this->createFreelancer('Freelancer Gamma', 4.2);

        // Verify all 3 appear in public discovery
        $res = $this->getJson('/api/v1/freelancers?sort=rating');
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertEquals($f1['profile']->id, $res->json('data.0.id'));

        // Admin deletes Freelancer Alpha
        $delRes = $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$f1['user']->id}");
        $delRes->assertStatus(200);

        // Database checks
        $this->assertDatabaseMissing('users', ['id' => $f1['user']->id]);
        $this->assertDatabaseMissing('freelancer_profiles', ['id' => $f1['profile']->id]);

        // Verify public discovery now only returns Beta and Gamma, with Beta taking the top slot
        $resAfter = $this->getJson('/api/v1/freelancers?sort=rating');
        $resAfter->assertStatus(200);
        $this->assertCount(2, $resAfter->json('data'));
        $ids = collect($resAfter->json('data'))->pluck('id')->all();
        $this->assertNotContains($f1['profile']->id, $ids);
        $this->assertEquals($f2['profile']->id, $resAfter->json('data.0.id'));
        $this->assertEquals($f3['profile']->id, $resAfter->json('data.1.id'));

        // Direct public show returns 404 for deleted profile
        $this->getJson("/api/v1/freelancers/{$f1['profile']->id}")->assertStatus(404);
    }

    public function test_admin_deleting_freelancer_synchronizes_recommendations(): void
    {
        $category = Category::create(['name' => 'Web', 'slug' => 'web', 'is_active' => true]);
        $employer = $this->createEmployer('Hiring Corp');
        $this->createJob($employer, 'Need Laravel dev', $category->id, ['Laravel']);

        $f1 = $this->createFreelancer('Freelancer Laravel Master', 5.0, ['Laravel']);
        $f2 = $this->createFreelancer('Freelancer Laravel Junior', 4.5, ['Laravel']);

        // Employer gets recommendations including f1
        $recRes = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');
        $recRes->assertStatus(200);
        $recIds = collect($recRes->json('data'))->pluck('freelancer.id')->all();
        $this->assertContains($f1['profile']->id, $recIds);

        // Admin deletes f1
        $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$f1['user']->id}")
            ->assertStatus(200);

        // Employer recommendations no longer contain f1, f2 remains
        $recAfter = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/recommendations/freelancers');
        $recAfter->assertStatus(200);
        $recIdsAfter = collect($recAfter->json('data'))->pluck('freelancer.id')->all();
        $this->assertNotContains($f1['profile']->id, $recIdsAfter);
        $this->assertContains($f2['profile']->id, $recIdsAfter);
    }

    public function test_admin_deleting_freelancer_cleans_up_saved_freelancers(): void
    {
        $employer = $this->createEmployer('Employer Saver');
        $f1 = $this->createFreelancer('Freelancer ToSave');

        SavedFreelancer::create([
            'user_id' => $employer->id,
            'freelancer_profile_id' => $f1['profile']->id,
        ]);

        $savedRes = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers');
        $savedRes->assertStatus(200);
        $this->assertCount(1, $savedRes->json('data'));

        // Admin deletes freelancer
        $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$f1['user']->id}")
            ->assertStatus(200);

        // Saved list is now empty and pivot is deleted
        $this->assertDatabaseMissing('saved_freelancers', ['freelancer_profile_id' => $f1['profile']->id]);
        $savedAfter = $this->actingAsSanctum($employer)
            ->getJson('/api/v1/saved-freelancers');
        $savedAfter->assertStatus(200);
        $this->assertCount(0, $savedAfter->json('data'));
    }

    // ─── 2. EMPLOYER / JOBS DELETION SYNCHRONIZATION ────────────────────

    public function test_admin_deleting_employer_removes_employer_and_all_jobs_from_public(): void
    {
        $emp1 = $this->createEmployer('Employer Alpha');
        $emp2 = $this->createEmployer('Employer Beta');

        $job1 = $this->createJob($emp1, 'Job One Alpha');
        $job2 = $this->createJob($emp1, 'Job Two Alpha');
        $job3 = $this->createJob($emp2, 'Job Three Beta');

        // Verify all 3 jobs are open in public browse
        $browseRes = $this->getJson('/api/v1/jobs');
        $browseRes->assertStatus(200);
        $this->assertCount(3, $browseRes->json('data'));

        // Admin deletes Employer Alpha
        $delRes = $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$emp1->id}");
        $delRes->assertStatus(200);

        // Database checks
        $this->assertDatabaseMissing('users', ['id' => $emp1->id]);
        $this->assertDatabaseMissing('marketplace_jobs', ['id' => $job1->id]);
        $this->assertDatabaseMissing('marketplace_jobs', ['id' => $job2->id]);
        $this->assertDatabaseHas('marketplace_jobs', ['id' => $job3->id]);

        // Public browse now returns only Job 3
        $browseAfter = $this->getJson('/api/v1/jobs');
        $browseAfter->assertStatus(200);
        $this->assertCount(1, $browseAfter->json('data'));
        $this->assertEquals($job3->id, $browseAfter->json('data.0.id'));

        // Direct show for deleted jobs returns 404
        $this->getJson("/api/v1/jobs/{$job1->id}")->assertStatus(404);
        $this->getJson("/api/v1/jobs/{$job2->id}")->assertStatus(404);
    }

    public function test_admin_deleting_employer_cleans_up_saved_jobs_and_recommendations(): void
    {
        $category = Category::create(['name' => 'Design', 'slug' => 'design', 'is_active' => true]);
        $emp1 = $this->createEmployer('Creative Corp');
        $emp2 = $this->createEmployer('Tech Studio');

        $job1 = $this->createJob($emp1, 'UI Designer Position', $category->id, ['Figma']);
        $job2 = $this->createJob($emp2, 'Product Designer Position', $category->id, ['Figma']);

        $freelancer = $this->createFreelancer('Designer Jane', 4.9, ['Figma']);

        // Freelancer saves job1
        SavedJob::create([
            'user_id' => $freelancer['user']->id,
            'job_id' => $job1->id,
        ]);

        // Freelancer has saved jobs
        $savedRes = $this->actingAsSanctum($freelancer['user'])
            ->getJson('/api/v1/saved-jobs');
        $savedRes->assertStatus(200);
        $this->assertCount(1, $savedRes->json('data'));

        // Admin deletes emp1
        $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$emp1->id}")
            ->assertStatus(200);

        // Saved jobs list is now clean and does not contain job1
        $savedAfter = $this->actingAsSanctum($freelancer['user'])
            ->getJson('/api/v1/saved-jobs');
        $savedAfter->assertStatus(200);
        $this->assertCount(0, $savedAfter->json('data'));

        // Job recommendations now only contain job2, never job1
        $recRes = $this->actingAsSanctum($freelancer['user'])
            ->getJson('/api/v1/recommendations/jobs');
        $recRes->assertStatus(200);
        $recJobIds = collect($recRes->json('data'))->pluck('job.id')->all();
        $this->assertNotContains($job1->id, $recJobIds);
        $this->assertContains($job2->id, $recJobIds);
    }

    public function test_admin_deleting_employer_synchronizes_category_counts(): void
    {
        $cat = Category::create(['name' => 'Marketing', 'slug' => 'marketing', 'is_active' => true]);
        $emp1 = $this->createEmployer('Marketing Agency 1');
        $emp2 = $this->createEmployer('Marketing Agency 2');

        $this->createJob($emp1, 'SEO Specialist', $cat->id);
        $this->createJob($emp1, 'Content Writer', $cat->id);
        $this->createJob($emp2, 'Social Media Manager', $cat->id);

        $catRes = $this->getJson('/api/v1/categories');
        $catRes->assertStatus(200);
        $catData = collect($catRes->json('data'))->firstWhere('id', $cat->id);
        $this->assertEquals(3, $catData['jobs_count']);

        // Admin deletes emp1
        $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$emp1->id}")
            ->assertStatus(200);

        // Category jobs_count updates to 1
        $catAfter = $this->getJson('/api/v1/categories');
        $catAfter->assertStatus(200);
        $catDataAfter = collect($catAfter->json('data'))->firstWhere('id', $cat->id);
        $this->assertEquals(1, $catDataAfter['jobs_count']);
    }

    public function test_admin_can_delete_user_with_wallet_and_transaction_records(): void
    {
        $user = User::create([
            'name' => 'Exm test emp',
            'email' => 'exm-test-emp@example.com',
            'password' => bcrypt('password'),
            'role' => 'employer',
            'status' => 'active',
        ]);

        $wallet = Wallet::create([
            'user_id' => $user->id,
            'available_balance' => 50.00,
            'pending_balance' => 0.00,
            'held_balance' => 0.00,
            'total_earned' => 50.00,
            'total_withdrawn' => 0.00,
            'currency' => 'ETB',
        ]);

        Transaction::create([
            'reference' => 'TXN-DELETE-1',
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => 50.00,
            'balance_before' => 0.00,
            'balance_after' => 50.00,
            'currency' => 'ETB',
            'type' => 'payment',
            'direction' => 'credit',
            'status' => 'completed',
            'description' => 'Test wallet transaction before delete',
        ]);

        $response = $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/users/{$user->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
    }

    public function test_admin_deleting_job_directly_removes_it_and_replaces_in_browse(): void
    {
        $emp = $this->createEmployer('Enterprise Co');
        $job1 = $this->createJob($emp, 'First Listing');
        $job2 = $this->createJob($emp, 'Second Listing');

        // Admin deletes job1 directly
        $delRes = $this->actingAsSanctum($this->admin)
            ->deleteJson("/api/v1/admin/jobs/{$job1->id}");
        $delRes->assertStatus(200);

        $this->assertDatabaseMissing('marketplace_jobs', ['id' => $job1->id]);
        $this->assertDatabaseHas('marketplace_jobs', ['id' => $job2->id]);

        $browseRes = $this->getJson('/api/v1/jobs');
        $browseRes->assertStatus(200);
        $this->assertCount(1, $browseRes->json('data'));
        $this->assertEquals($job2->id, $browseRes->json('data.0.id'));
    }
}
