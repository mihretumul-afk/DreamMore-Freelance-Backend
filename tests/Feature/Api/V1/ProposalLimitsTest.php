<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Credential;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProposalLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private string $freelancerToken;
    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::create([
            'name'     => 'Freelancer User',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $this->freelancerToken = $this->freelancer->createToken('freelancer_token')->plainTextToken;

        FreelancerProfile::create(['user_id' => $this->freelancer->id]);

        // Create approved credentials for the freelancer
        Credential::create([
            'user_id'    => $this->freelancer->id,
            'title'      => 'ID Document',
            'type'       => 'external_certificate',
            'file_path'  => 'credentials/test.pdf',
            'status'     => 'approved',
        ]);

        $category = Category::create(['name' => 'Web Dev', 'slug' => 'web-dev-' . Str::random(4)]);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . Str::random(4), 'category_id' => $category->id]);

        $employer = User::create([
            'name'     => 'Employer',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);

        $this->job = Job::create([
            'employer_id' => $employer->id,
            'title'       => 'Test Job',
            'slug'        => 'test-job-' . Str::random(4),
            'description' => 'A test job.',
            'status'      => 'open',
        ]);
    }

    public function test_proposal_within_limits_accepted(): void
    {
        AdminSetting::setValue('min_proposal_amount', '100', 'string');
        AdminSetting::setValue('max_proposal_amount', '5000', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 1000,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }

    public function test_proposal_below_minimum_rejected(): void
    {
        AdminSetting::setValue('min_proposal_amount', '500', 'string');
        AdminSetting::setValue('max_proposal_amount', '', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 100,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['bid_amount']);
    }

    public function test_proposal_above_maximum_rejected(): void
    {
        AdminSetting::setValue('min_proposal_amount', '', 'string');
        AdminSetting::setValue('max_proposal_amount', '1000', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 5000,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['bid_amount']);
    }

    public function test_no_limits_when_empty(): void
    {
        AdminSetting::setValue('min_proposal_amount', '', 'string');
        AdminSetting::setValue('max_proposal_amount', '', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 50,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }

    public function test_proposal_at_exact_minimum_accepted(): void
    {
        AdminSetting::setValue('min_proposal_amount', '500', 'string');
        AdminSetting::setValue('max_proposal_amount', '', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 500,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }

    public function test_proposal_at_exact_maximum_accepted(): void
    {
        AdminSetting::setValue('min_proposal_amount', '', 'string');
        AdminSetting::setValue('max_proposal_amount', '5000', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
                         ->postJson("/api/v1/jobs/{$this->job->id}/proposals", [
                             'cover_letter'       => 'I can do this.',
                             'bid_amount'         => 5000,
                             'estimated_duration' => '1 week',
                         ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);
    }
}
