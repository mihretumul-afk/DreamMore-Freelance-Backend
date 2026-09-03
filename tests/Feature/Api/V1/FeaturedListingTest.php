<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use App\Models\FeaturedJob;
use App\Models\FeaturedProfile;
use App\Models\Job;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeaturedListingTest extends TestCase
{
    use RefreshDatabase;

    private User $employer;
    private User $freelancer;
    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable both feature flags so the controller doesn't short-circuit
        AdminSetting::setValue('featured_jobs_enabled', 'true', 'boolean');
        AdminSetting::setValue('featured_profiles_enabled', 'true', 'boolean');
        AdminSetting::setValue('featured_job_price', '150', 'string');
        AdminSetting::setValue('featured_job_duration_days', '7', 'string');
        AdminSetting::setValue('featured_profile_price', '100', 'string');
        AdminSetting::setValue('featured_profile_duration_days', '7', 'string');

        // Create employer — deliberately do NOT create a wallet row
        $this->employer = User::factory()->create([
            'role'   => 'employer',
            'status' => 'active',
        ]);

        // Create freelancer — deliberately do NOT create a wallet row
        $this->freelancer = User::factory()->create([
            'role'   => 'freelancer',
            'status' => 'active',
        ]);

        // Create a job owned by the employer (required by the feature-job endpoint)
        $this->job = Job::create([
            'employer_id'  => $this->employer->id,
            'title'        => 'Test Job for Featuring',
            'slug'         => 'test-job-' . Str::random(8),
            'description'  => 'A test job.',
            'budget_type'  => 'fixed',
            'min_budget'   => 50000,
            'max_budget'   => 50000,
            'location'     => 'Addis Ababa',
            'status'       => 'open',
        ]);
    }

    // ── TEST 1: Fresh employer with NO wallet → Insufficient balance ──

    public function test_feature_job_fresh_employer_with_no_wallet_shows_insufficient_balance(): void
    {
        // Confirm no wallet row exists for this employer
        $this->assertDatabaseMissing('wallets', [
            'user_id' => $this->employer->id,
        ]);

        $response = $this->actingAs($this->employer, 'sanctum')
            ->postJson("/api/v1/jobs/{$this->job->id}/feature");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = $response->json('message');
        $this->assertStringContainsString('Insufficient balance', $message);
        $this->assertStringContainsString('150', $message);
        $this->assertStringContainsString('0.00', $message);
        // Must NOT contain the old "No wallet found" error
        $this->assertStringNotContainsString('No wallet found', $message);

        // Wallet::forUser() auto-created the row inside the transaction, enabling the
        // correct balance check — but the transaction rolled back (exception), so the
        // wallet row does not persist. That's fine; the important thing is the error message.
    }

    // ── TEST 2: Fresh freelancer with NO wallet → Insufficient balance ──

    public function test_feature_profile_fresh_freelancer_with_no_wallet_shows_insufficient_balance(): void
    {
        // Confirm no wallet row exists for this freelancer
        $this->assertDatabaseMissing('wallets', [
            'user_id' => $this->freelancer->id,
        ]);

        $response = $this->actingAs($this->freelancer, 'sanctum')
            ->postJson('/api/v1/freelancer-profile/feature');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = $response->json('message');
        $this->assertStringContainsString('Insufficient balance', $message);
        $this->assertStringContainsString('100', $message);
        $this->assertStringContainsString('0.00', $message);
        $this->assertStringNotContainsString('No wallet found', $message);

        // Wallet::forUser() auto-created the row inside the transaction, enabling the
        // correct balance check — but the transaction rolled back (exception), so the
        // wallet row does not persist. That's fine; the important thing is the error message.
    }

    // ── TEST 3: Wallet with 500 ETB → successful feature job ──

    public function test_feature_job_with_sufficient_funds_succeeds(): void
    {
        // Create wallet with 500 ETB
        $wallet = Wallet::forUser($this->employer->id);
        $wallet->increment('available_balance', 500);

        $this->assertDatabaseHas('wallets', [
            'user_id'           => $this->employer->id,
            'available_balance' => 500,
        ]);

        $response = $this->actingAs($this->employer, 'sanctum')
            ->postJson("/api/v1/jobs/{$this->job->id}/feature");

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.featured_job.amount_paid', 150)
            ->assertJsonPath('data.featured_job.duration_days', 7)
            ->assertJsonPath('data.featured_job.status', 'active');

        // Wallet debited: 500 - 150 = 350
        $wallet->refresh();
        $this->assertEquals(350.00, (float) $wallet->available_balance);

        // FeaturedJobs row created
        $this->assertDatabaseHas('featured_jobs', [
            'job_id'        => $this->job->id,
            'employer_id'   => $this->employer->id,
            'amount_paid'   => 150,
            'duration_days' => 7,
            'status'        => 'active',
        ]);

        // Transaction ledger entry
        $this->assertDatabaseHas('transactions', [
            'user_id'  => $this->employer->id,
            'type'     => Transaction::TYPE_FEATURED_JOB_FEE,
            'direction' => Transaction::DIR_DEBIT,
            'amount'   => 150,
        ]);
    }

    // ── TEST 4: Wallet with 500 ETB → successful feature profile ──

    public function test_feature_profile_with_sufficient_funds_succeeds(): void
    {
        // Create wallet with 500 ETB
        $wallet = Wallet::forUser($this->freelancer->id);
        $wallet->increment('available_balance', 500);

        $response = $this->actingAs($this->freelancer, 'sanctum')
            ->postJson('/api/v1/freelancer-profile/feature');

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.featured_profile.amount_paid', 100)
            ->assertJsonPath('data.featured_profile.duration_days', 7)
            ->assertJsonPath('data.featured_profile.status', 'active');

        // Wallet debited: 500 - 100 = 400
        $wallet->refresh();
        $this->assertEquals(400.00, (float) $wallet->available_balance);

        // FeaturedProfile row created
        $this->assertDatabaseHas('featured_profiles', [
            'user_id'       => $this->freelancer->id,
            'amount_paid'   => 100,
            'duration_days' => 7,
            'status'        => 'active',
        ]);

        // Transaction ledger entry
        $this->assertDatabaseHas('transactions', [
            'user_id'  => $this->freelancer->id,
            'type'     => Transaction::TYPE_FEATURED_PROFILE_FEE,
            'direction' => Transaction::DIR_DEBIT,
            'amount'   => 100,
        ]);
    }
}
