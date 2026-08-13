<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Contract;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_tables_and_relationships_can_be_created(): void
    {
        // 1. Create Freelancer User & Profile
        $freelancerUser = User::create([
            'name' => 'Alice Freelancer',
            'email' => 'alice@example.com',
            'password' => bcrypt('password123'),
            'role' => 'freelancer',
        ]);
        $freelancerProfile = FreelancerProfile::create([
            'user_id' => $freelancerUser->id,
            'headline' => 'Senior Full Stack Developer',
            'hourly_rate' => 75.00,
        ]);

        // 2. Create Employer User & Profile
        $employerUser = User::create([
            'name' => 'Bob Employer',
            'email' => 'bob@example.com',
            'password' => bcrypt('password123'),
            'role' => 'employer',
        ]);
        $employerProfile = EmployerProfile::create([
            'user_id' => $employerUser->id,
            'company_name' => 'Tech Corp',
        ]);

        // 3. Create Category & Skill
        $category = Category::create([
            'name' => 'Web Development',
            'slug' => 'web-development',
        ]);
        $skill = Skill::create([
            'category_id' => $category->id,
            'name' => 'React',
            'slug' => 'react',
        ]);

        // Attach Skill to Freelancer
        $freelancerProfile->skills()->attach($skill->id, ['years_of_experience' => 5]);

        // 4. Create Job
        $job = Job::create([
            'employer_id' => $employerUser->id,
            'category_id' => $category->id,
            'title' => 'Build React Application',
            'slug' => 'build-react-application',
            'description' => 'Need an expert React developer',
            'budget_type' => 'fixed',
            'min_budget' => 1000.00,
            'max_budget' => 2000.00,
        ]);
        $job->skills()->attach($skill->id);

        // 5. Create Proposal
        $proposal = Proposal::create([
            'job_id' => $job->id,
            'freelancer_id' => $freelancerUser->id,
            'cover_letter' => 'I am an expert in React',
            'bid_amount' => 1500.00,
            'estimated_duration' => '2 weeks',
        ]);

        // 6. Create Contract & Milestone
        $contract = Contract::create([
            'job_id' => $job->id,
            'proposal_id' => $proposal->id,
            'employer_id' => $employerUser->id,
            'freelancer_id' => $freelancerUser->id,
            'title' => 'Contract for Build React Application',
            'agreed_rate' => 1500.00,
            'total_amount' => 1500.00,
        ]);
        $milestone = Milestone::create([
            'contract_id' => $contract->id,
            'title' => 'Phase 1 - Frontend Architecture',
            'amount' => 750.00,
        ]);

        // 7. Create Review
        $review = Review::create([
            'contract_id' => $contract->id,
            'reviewer_id' => $employerUser->id,
            'reviewee_id' => $freelancerUser->id,
            'reviewer_role' => 'employer',
            'rating' => 5,
            'comment' => 'Outstanding work!',
        ]);

        // Assertions
        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'role' => 'freelancer']);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com', 'role' => 'employer']);
        $this->assertDatabaseHas('freelancer_profiles', ['headline' => 'Senior Full Stack Developer']);
        $this->assertDatabaseHas('employer_profiles', ['company_name' => 'Tech Corp']);
        $this->assertDatabaseHas('categories', ['slug' => 'web-development']);
        $this->assertDatabaseHas('skills', ['slug' => 'react']);
        $this->assertDatabaseHas('marketplace_jobs', ['slug' => 'build-react-application']);
        $this->assertDatabaseHas('proposals', ['bid_amount' => 1500.00]);
        $this->assertDatabaseHas('contracts', ['total_amount' => 1500.00]);
        $this->assertDatabaseHas('milestones', ['amount' => 750.00]);
        $this->assertDatabaseHas('reviews', ['rating' => 5]);

        // Verify Relationships
        $this->assertEquals($freelancerUser->id, $freelancerProfile->user->id);
        $this->assertEquals($employerUser->id, $job->employer->id);
        $this->assertEquals('React', $freelancerProfile->skills->first()->name);
        $this->assertEquals('React', $job->skills->first()->name);
        $this->assertEquals($contract->id, $milestone->contract->id);
        $this->assertEquals(5, $review->rating);
    }
}
