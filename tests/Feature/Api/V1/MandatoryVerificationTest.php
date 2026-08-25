<?php

namespace Tests\Feature\Api\V1;

use App\Models\Credential;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

class MandatoryVerificationTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    private const BLOCKED_MESSAGE = 'Your credentials must be approved by an administrator before you can apply for jobs.';

    private function createJob(User $employer): Job
    {
        return Job::create([
            'employer_id' => $employer->id,
            'title' => 'Build a Mobile App',
            'slug' => 'job-' . Str::random(8),
            'description' => 'Looking for a senior React Native developer.',
            'budget_type' => 'fixed',
            'min_budget' => 50000,
            'max_budget' => 100000,
            'location' => 'Addis Ababa',
        ]);
    }

    public function test_unverified_freelancer_with_no_credentials_cannot_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I have 5 years experience in building mobile apps.',
                'bid_amount' => 75000,
                'estimated_duration' => '3 weeks',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::BLOCKED_MESSAGE);

        $this->assertDatabaseMissing('proposals', [
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
        ]);
    }

    public function test_freelancer_with_pending_credentials_cannot_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        // Submit a pending credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'BSc Computer Science',
            'type' => 'professional_qualification',
            'file_path' => 'credentials/bsc.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I have 5 years experience in building mobile apps.',
                'bid_amount' => 75000,
                'estimated_duration' => '3 weeks',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::BLOCKED_MESSAGE);

        $this->assertDatabaseMissing('proposals', [
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
        ]);
    }

    public function test_freelancer_with_rejected_credentials_cannot_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        // Submit a rejected credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Expired Certificate',
            'type' => 'external_certificate',
            'file_path' => 'credentials/cert.pdf',
            'status' => 'rejected',
            'rejection_reason' => 'Document is expired and unreadable.',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I have 5 years experience in building mobile apps.',
                'bid_amount' => 75000,
                'estimated_duration' => '3 weeks',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', self::BLOCKED_MESSAGE);

        $this->assertDatabaseMissing('proposals', [
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
        ]);
    }

    public function test_freelancer_with_approved_credential_can_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createVerifiedFreelancer();
        $job = $this->createJob($employer);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I can deliver this React Native project with top quality.',
                'bid_amount' => 60000,
                'estimated_duration' => '4 weeks',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', $job->id)
            ->assertJsonPath('data.freelancer_id', $freelancer->id)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('proposals', [
            'job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'status' => 'pending',
        ]);
    }

    public function test_freelancer_with_approved_identity_verification_can_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        Verification::create([
            'user_id' => $freelancer->id,
            'type' => 'national_id',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I am an ID-verified developer.',
                'bid_amount' => 55000,
                'estimated_duration' => '2 weeks',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', $job->id);
    }

    public function test_freelancer_with_lms_auto_verified_credential_can_apply_for_job(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Dream More Certified Web Developer',
            'type' => Credential::TYPE_DREAM_MORE,
            'verification_source' => Credential::SOURCE_LMS,
            'auto_verified' => true,
            'status' => 'approved',
            'file_path' => 'credentials/lms_cert.pdf',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'I am certified by Dream More LMS.',
                'bid_amount' => 65000,
                'estimated_duration' => '3 weeks',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_freelancer_with_multiple_credentials_where_one_is_approved_can_apply(): void
    {
        $employer = $this->createContractUser('employer');
        $freelancer = $this->createContractUser('freelancer');
        FreelancerProfile::create(['user_id' => $freelancer->id, 'approval_status' => 'approved']);
        $job = $this->createJob($employer);

        // One rejected credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Old Draft Cert',
            'type' => 'other',
            'file_path' => 'credentials/old.pdf',
            'status' => 'rejected',
        ]);

        // One pending credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Pending Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/pending.pdf',
            'status' => 'pending',
        ]);

        // One approved credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Official Diploma',
            'type' => 'professional_qualification',
            'file_path' => 'credentials/diploma.pdf',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $this->assertTrue($freelancer->hasApprovedCredentials());
        $this->assertEquals('approved', $freelancer->verificationStatus());

        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/jobs/{$job->id}/proposals", [
                'cover_letter' => 'Applying with approved diploma.',
                'bid_amount' => 70000,
                'estimated_duration' => '4 weeks',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_auth_me_returns_verification_status_and_is_verified(): void
    {
        $freelancer = $this->createContractUser('freelancer');

        // Initially unverified
        $response = $this->actingAsSanctum($freelancer)->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.has_approved_credentials', false);

        // After submitting a pending credential
        Credential::create([
            'user_id' => $freelancer->id,
            'title' => 'Pending Cert',
            'type' => 'external_certificate',
            'file_path' => 'credentials/pending.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAsSanctum($freelancer)->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.has_approved_credentials', false);

        // After approval
        $freelancer->credentials()->first()->update(['status' => 'approved', 'reviewed_at' => now()]);

        $response = $this->actingAsSanctum($freelancer)->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.verification_status', 'approved')
            ->assertJsonPath('data.has_approved_credentials', true);
    }
}
