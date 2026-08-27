<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ContractTestHelpers;
use Tests\TestCase;

/**
 * Complete payment lifecycle integration test.
 *
 * Walks through the entire financial flow:
 *   Fund → Start → Submit → Approve → Release → Withdraw → Admin Process
 *
 * Also tests:
 *   - Idempotency (double-fund, double-release, double-withdrawal prevention)
 *   - Access control (unauthorized users blocked)
 *   - Balance calculations
 *   - Transaction history
 *   - Audit logs
 *   - Notifications
 *   - Dispute blocks release
 */
class PaymentLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use ContractTestHelpers;

    // ── Helpers ────────────────────────────────────────────────────────

    private function createFinanceAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        // Ensure super_admin role exists and assign it
        $role = Role::firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => 'Super Admin', 'is_system' => true]
        );

        $admin->adminRoles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createPaymentMethodFor(User $user, string $type = 'mobile_money'): PaymentMethod
    {
        return PaymentMethod::create([
            'user_id' => $user->id,
            'type' => $type,
            'nickname' => 'Test Method',
            'provider' => 'manual',
            'display_label' => 'Telebirr •••• 234',
            'is_default' => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 1: Complete Financial Lifecycle
    // ═══════════════════════════════════════════════════════════════════

    public function test_complete_financial_lifecycle_fund_start_submit_approve_release_withdraw_admin_process(): void
    {
        // ── Setup ──────────────────────────────────────────────────────
        $data = $this->createContract();
        $employer = $data['employer'];
        $freelancer = $data['freelancer'];
        $contract = $data['contract'];
        $admin = $this->createFinanceAdmin();

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        // Employer needs a payment method for funding escrow
        $employerPm = $this->createPaymentMethodFor($employer);

        // Freelancer needs a payment method for withdrawals
        $freelancerPm = $this->createPaymentMethodFor($freelancer);

        // ════════════════════════════════════════════════════════════════
        // STEP 1: Employer creates milestone
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones", [
                'title' => 'Mobile App MVP',
                'description' => 'Build the initial MVP of the mobile app.',
                'amount' => 50000,
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'awaiting_funding');

        $milestone = Milestone::where('contract_id', $contract->id)->first();
        $this->assertEquals('awaiting_funding', $milestone->status);

        // ════════════════════════════════════════════════════════════════
        // STEP 2: Freelancer CANNOT start work before funding
        // ════════════════════════════════════════════════════════════════
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/start")
            ->assertStatus(422);

        // ════════════════════════════════════════════════════════════════
        // STEP 3: Employer funds milestone → escrow funded
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/fund", [
                'payment_method_id' => $employerPm->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $milestone->refresh();
        $this->assertEquals(Milestone::STATUS_FUNDED, $milestone->status);
        $this->assertNotNull($milestone->escrow_funded_at);
        $this->assertNotNull($milestone->payment_id);

        // Payment record created
        $payment = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->first();
        $this->assertNotNull($payment);
        $this->assertEquals(Payment::STATUS_COMPLETED, $payment->status);
        $this->assertEquals(50000, (float) $payment->amount);
        $this->assertEquals($employer->id, $payment->payer_id);
        $this->assertEquals($freelancer->id, $payment->payee_id);

        // Debit transaction created for employer
        $this->assertDatabaseHas('transactions', [
            'user_id' => $employer->id,
            'direction' => Transaction::DIRECTION_DEBIT,
            'type' => Payment::TYPE_ESCROW_FUNDED,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_funded',
        ]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'Milestone',
            'subject_id' => $milestone->id,
            'action' => AuditLog::ACTION_MILESTONE_FUNDED,
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 4: Freelancer starts work
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $milestone->refresh();
        $this->assertNotNull($milestone->started_at);

        // Employer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'milestone_started',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 5: Freelancer submits work
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/submit", [
                'description' => 'MVP completed with core features: auth, dashboard, payments.',
                'links' => ['https://github.com/example/mvp-repo'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');

        $milestone->refresh();
        $this->assertEquals(Milestone::STATUS_SUBMITTED, $milestone->status);
        $this->assertNotNull($milestone->submitted_at);

        // Employer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'milestone_submitted',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 6: Employer approves
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $milestone->refresh();
        $this->assertEquals(Milestone::STATUS_APPROVED, $milestone->status);
        $this->assertNotNull($milestone->approved_at);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_approved',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 7: Employer releases payment → escrow → freelancer
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($employer)
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/release");

        $response->assertStatus(200);

        $milestone->refresh();
        $this->assertEquals(Milestone::STATUS_PAID, $milestone->status);
        $this->assertNotNull($milestone->paid_at);

        // Release payment created
        $releasePayment = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->first();
        $this->assertNotNull($releasePayment);
        $this->assertEquals(Payment::STATUS_COMPLETED, $releasePayment->status);

        // Freelancer credit transaction created
        $creditTxn = Transaction::where('user_id', $freelancer->id)
            ->where('direction', Transaction::DIRECTION_CREDIT)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->where('status', Payment::STATUS_COMPLETED)
            ->first();
        $this->assertNotNull($creditTxn);
        $this->assertGreaterThan(0, (float) $creditTxn->amount);

        // Freelancer earnings updated
        $profile = FreelancerProfile::where('user_id', $freelancer->id)->first();
        $this->assertGreaterThan(0, (float) $profile->total_earnings);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'milestone_paid',
        ]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'Milestone',
            'subject_id' => $milestone->id,
            'action' => AuditLog::ACTION_MILESTONE_RELEASED,
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 8: Verify balance summary
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/balance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $balance = $response->json('data');
        $this->assertGreaterThan(0, $balance['total_earned']);
        $this->assertGreaterThan(0, $balance['available_earnings']);
        $this->assertEquals('ETB', $balance['currency']);

        // ════════════════════════════════════════════════════════════════
        // STEP 9: Verify transaction history
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/transactions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $transactions = $response->json('data');
        $this->assertNotEmpty($transactions);

        // Freelancer should have a credit transaction for this milestone
        $creditTxns = collect($transactions)->filter(fn ($t) => $t['direction'] === 'credit');
        $this->assertGreaterThan(0, $creditTxns->count());

        // ════════════════════════════════════════════════════════════════
        // STEP 10: Freelancer requests withdrawal
        // ════════════════════════════════════════════════════════════════
        $available = $balance['available_earnings'];
        $withdrawAmount = min($available, 10000);

        $response = $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => $withdrawAmount,
                'payment_method_id' => $freelancerPm->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $withdrawal = Withdrawal::where('user_id', $freelancer->id)->first();
        $this->assertNotNull($withdrawal);
        $this->assertEquals(Withdrawal::STATUS_REQUESTED, $withdrawal->status);
        $this->assertEquals($withdrawAmount, (float) $withdrawal->amount);
        $this->assertGreaterThan(0, (float) $withdrawal->fee);
        $this->assertGreaterThan(0, (float) $withdrawal->net_amount);

        // Withdrawal debit transaction created (pending)
        $this->assertDatabaseHas('transactions', [
            'user_id' => $freelancer->id,
            'type' => 'withdrawal',
            'direction' => Transaction::DIRECTION_DEBIT,
            'status' => Payment::STATUS_PENDING,
        ]);

        // Admin notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'withdrawal_requested',
        ]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'Withdrawal',
            'subject_id' => $withdrawal->id,
            'action' => AuditLog::ACTION_PAYMENT_PROCESSED,
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 11: Freelancer CANNOT create duplicate withdrawal
        // ════════════════════════════════════════════════════════════════
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $freelancerPm->id,
            ])
            ->assertStatus(422);

        // ════════════════════════════════════════════════════════════════
        // STEP 12: Freelancer can view own withdrawals
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/withdrawals');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $withdrawals = $response->json('data');
        $this->assertNotEmpty($withdrawals);
        $this->assertEquals($withdrawal->reference, $withdrawals[0]['reference']);

        // ════════════════════════════════════════════════════════════════
        // STEP 13: Freelancer CANNOT see other users' withdrawals
        // ════════════════════════════════════════════════════════════════
        $otherFreelancer = $this->createContractUser('freelancer');
        $this->actingAsSanctum($otherFreelancer)
            ->getJson('/api/v1/payments/withdrawals')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // ════════════════════════════════════════════════════════════════
        // STEP 14: Admin processes withdrawal
        // In manual mode, processWithdrawal auto-completes the withdrawal.
        // For real providers, it would stay in 'processing' until a webhook.
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($admin)
            ->putJson("/api/v1/admin/withdrawals/{$withdrawal->id}/process", [
                'note' => 'Initiated bank transfer',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $withdrawal->refresh();
        // Manual provider auto-completes: status goes directly to 'completed'
        $this->assertEquals(Withdrawal::STATUS_COMPLETED, $withdrawal->status);
        $this->assertNotNull($withdrawal->processed_at);
        $this->assertNotNull($withdrawal->completed_at);
        $this->assertEquals($admin->id, $withdrawal->processed_by);

        // Withdrawal debit transaction updated to completed
        $this->assertDatabaseHas('transactions', [
            'user_id' => $freelancer->id,
            'type' => 'withdrawal',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // Freelancer notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $freelancer->id,
            'type' => 'withdrawal_completed',
        ]);

        // ════════════════════════════════════════════════════════════════
        // STEP 16: Verify updated balance after withdrawal
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/balance');

        $response->assertStatus(200);

        $finalBalance = $response->json('data');
        $this->assertGreaterThan(0, (float) $finalBalance['withdrawn']);
        $this->assertEquals('ETB', $finalBalance['currency']);

        // Available earnings should be less than before (after withdrawal)
        $this->assertLessThan($available, $finalBalance['available_earnings']);

        // ════════════════════════════════════════════════════════════════
        // STEP 17: Verify transaction history includes all records
        // ════════════════════════════════════════════════════════════════
        $response = $this->actingAsSanctum($freelancer)
            ->getJson('/api/v1/payments/transactions');

        $response->assertStatus(200);

        $allTxns = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($allTxns));

        // Has credit for milestone release
        $creditTypes = collect($allTxns)->pluck('type')->filter(fn ($t) => $t === Payment::TYPE_MILESTONE_RELEASED)->values();
        $this->assertGreaterThan(0, $creditTypes->count());

        // Has debit for withdrawal
        $debitTypes = collect($allTxns)->pluck('type')->filter(fn ($t) => $t === 'withdrawal')->values();
        $this->assertGreaterThan(0, $debitTypes->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 2: Double-Fund Prevention
    // ═══════════════════════════════════════════════════════════════════

    public function test_double_funding_prevention(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract']);
        $pm = $this->createPaymentMethodFor($data['employer']);

        // First fund succeeds
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/fund", [
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(200);

        // Second fund is blocked
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/fund", [
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(422);

        // Only one payment record
        $paymentCount = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->count();
        $this->assertEquals(1, $paymentCount);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 3: Double-Release Prevention
    // ═══════════════════════════════════════════════════════════════════

    public function test_double_release_prevention(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        // First release succeeds
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // Second release is blocked
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);

        // Only one release payment
        $releaseCount = Payment::where('milestone_id', $milestone->id)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->count();
        $this->assertEquals(1, $releaseCount);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 4: Double-Withdrawal Prevention
    // ═══════════════════════════════════════════════════════════════════

    public function test_double_withdrawal_prevention(): void
    {
        $data = $this->createContract();
        $freelancer = $data['freelancer'];
        $contract = $data['contract'];

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        $pm = $this->createPaymentMethodFor($freelancer);

        // Fund + Release to give freelancer balance
        $milestone = $this->createMilestone($contract, [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // First withdrawal
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(201);

        // Second withdrawal blocked
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(422);

        // Only one withdrawal
        $withdrawalCount = Withdrawal::where('user_id', $freelancer->id)->count();
        $this->assertEquals(1, $withdrawalCount);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 5: Access Control
    // ═══════════════════════════════════════════════════════════════════

    public function test_unauthorized_users_cannot_access_financial_endpoints(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'funded',
            'escrow_funded_at' => now(),
        ]);

        // Freelancer cannot fund
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/fund")
            ->assertStatus(403);

        // Freelancer cannot release
        $milestone->update(['status' => 'approved', 'approved_at' => now()]);
        $this->actingAsSanctum($data['freelancer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(403);

        // Employer cannot start work
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/start")
            ->assertStatus(403);

        // Employer cannot submit work
        $milestone->update(['status' => 'in_progress']);
        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/submit", [
                'description' => 'Test submission',
            ])
            ->assertStatus(403);

        // Reset auth to test unauthenticated access
        $this->app['auth']->forgetGuards();

        // Unauthenticated user cannot access payment endpoints
        $this->getJson('/api/v1/payments/balance')
            ->assertStatus(401);

        $this->getJson('/api/v1/payments/withdrawals')
            ->assertStatus(401);

        $this->postJson('/api/v1/payments/withdrawals', [
            'amount' => 5000,
        ])->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 6: Support Admin Cannot Process Finance Actions
    // ═══════════════════════════════════════════════════════════════════

    public function test_support_admin_cannot_process_withdrawals(): void
    {
        $data = $this->createContract();
        $freelancer = $data['freelancer'];

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        $pm = $this->createPaymentMethodFor($freelancer);

        // Fund + Release
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // Create withdrawal
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(201);

        $withdrawal = Withdrawal::where('user_id', $freelancer->id)->first();

        // Create support admin (without finance permissions)
        $supportAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $supportRole = Role::firstOrCreate(
            ['slug' => 'support_admin'],
            ['name' => 'Support Admin', 'is_system' => true]
        );
        $supportAdmin->adminRoles()->syncWithoutDetaching([$supportRole->id]);

        // Support admin CANNOT process withdrawal
        $this->actingAsSanctum($supportAdmin)
            ->putJson("/api/v1/admin/withdrawals/{$withdrawal->id}/process")
            ->assertStatus(403);

        // Support admin CANNOT complete withdrawal
        $this->actingAsSanctum($supportAdmin)
            ->putJson("/api/v1/admin/withdrawals/{$withdrawal->id}/complete")
            ->assertStatus(403);

        // Support admin CANNOT reject withdrawal
        $this->actingAsSanctum($supportAdmin)
            ->putJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", [
                'reason' => 'Not authorized',
            ])
            ->assertStatus(403);

        // Support admin CANNOT view withdrawal details
        $this->actingAsSanctum($supportAdmin)
            ->getJson("/api/v1/admin/withdrawals/{$withdrawal->id}")
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 7: Dispute Blocks Release
    // ═══════════════════════════════════════════════════════════════════

    public function test_disputed_milestone_cannot_be_released(): void
    {
        $data = $this->createContract();
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'disputed',
            'escrow_funded_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(422);
    }

    public function test_disputed_contract_blocks_funding(): void
    {
        $data = $this->createContract();
        $contract = $data['contract'];
        $contract->update(['status' => 'disputed']);

        $milestone = $this->createMilestone($contract);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$contract->id}/milestones/{$milestone->id}/fund")
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 8: Withdrawal Exceeds Balance
    // ═══════════════════════════════════════════════════════════════════

    public function test_withdrawal_exceeding_balance_is_rejected(): void
    {
        $data = $this->createContract();
        $freelancer = $data['freelancer'];

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        $pm = $this->createPaymentMethodFor($freelancer);

        // Give freelancer a small balance (milestone of 5000, net ~4750 after 5% fee)
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'amount' => 5000,
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // Try to withdraw more than available
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 999999,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 9: Freelancer Can Cancel Pending Withdrawal
    // ═══════════════════════════════════════════════════════════════════

    public function test_freelancer_can_cancel_pending_withdrawal(): void
    {
        $data = $this->createContract();
        $freelancer = $data['freelancer'];

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        $pm = $this->createPaymentMethodFor($freelancer);

        // Fund + Release
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // Create withdrawal
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(201);

        $withdrawal = Withdrawal::where('user_id', $freelancer->id)->first();

        // Cancel it
        $this->actingAsSanctum($freelancer)
            ->postJson("/api/v1/payments/withdrawals/{$withdrawal->id}/cancel")
            ->assertStatus(200);

        $withdrawal->refresh();
        $this->assertEquals(Withdrawal::STATUS_CANCELLED, $withdrawal->status);

        // Debit transaction should be cancelled
        $this->assertDatabaseHas('transactions', [
            'user_id' => $freelancer->id,
            'type' => 'withdrawal',
            'status' => Payment::STATUS_CANCELLED,
        ]);

        // Now they can create a new withdrawal
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 3000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(201);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 10: Admin Rejects Withdrawal
    // ═══════════════════════════════════════════════════════════════════

    public function test_admin_can_reject_withdrawal(): void
    {
        $data = $this->createContract();
        $freelancer = $data['freelancer'];
        $admin = $this->createFinanceAdmin();

        FreelancerProfile::create([
            'user_id' => $freelancer->id,
            'approval_status' => 'approved',
            'total_earnings' => 0,
        ]);

        $pm = $this->createPaymentMethodFor($freelancer);

        // Fund + Release
        $milestone = $this->createMilestone($data['contract'], [
            'status' => 'approved',
            'escrow_funded_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAsSanctum($data['employer'])
            ->postJson("/api/v1/contracts/{$data['contract']->id}/milestones/{$milestone->id}/release")
            ->assertStatus(200);

        // Create withdrawal
        $this->actingAsSanctum($freelancer)
            ->postJson('/api/v1/payments/withdrawals', [
                'amount' => 5000,
                'payment_method_id' => $pm->id,
            ])
            ->assertStatus(201);

        $withdrawal = Withdrawal::where('user_id', $freelancer->id)->first();

        // Admin rejects
        $response = $this->actingAsSanctum($admin)
            ->putJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", [
                'reason' => 'Incomplete KYC verification documents',
            ]);

        $response->assertStatus(200);

        $withdrawal->refresh();
        $this->assertEquals(Withdrawal::STATUS_REJECTED, $withdrawal->status);
        $this->assertEquals('Incomplete KYC verification documents', $withdrawal->rejection_reason);
        $this->assertNotNull($withdrawal->rejected_at);

        // Debit transaction cancelled (money returned to balance)
        $this->assertDatabaseHas('transactions', [
            'user_id' => $freelancer->id,
            'type' => 'withdrawal',
            'status' => Payment::STATUS_CANCELLED,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 11: Fee Calculation Accuracy
    // ═══════════════════════════════════════════════════════════════════

    public function test_fee_calculation_is_accurate(): void
    {
        $fees = \App\Services\PaymentService::calculateFees(10000);

        // Platform fee = 5% = 500
        $this->assertEquals(500, $fees['platform_fee']);
        // Processing fee = 2% = 200
        $this->assertEquals(200, $fees['processing_fee']);
        // Total fee = 700
        $this->assertEquals(700, $fees['total_fee']);
        // Net = 9300
        $this->assertEquals(9300, $fees['net']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 12: Payment Method Management
    // ═══════════════════════════════════════════════════════════════════

    public function test_payment_method_crud(): void
    {
        $user = $this->createContractUser('freelancer');

        // Add method
        $response = $this->actingAsSanctum($user)
            ->postJson('/api/v1/payments/methods', [
                'type' => 'mobile_money',
                'mobile_provider' => 'Telebirr',
                'masked_phone' => '+251 9** *** 234',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $method = PaymentMethod::where('user_id', $user->id)->first();
        $this->assertNotNull($method);
        $this->assertTrue($method->is_default); // First method auto-defaults

        // Add second method
        $this->actingAsSanctum($user)
            ->postJson('/api/v1/payments/methods', [
                'type' => 'bank_account',
                'bank_name' => 'CBE',
                'account_name' => 'Test User',
                'masked_account_number' => '****9012',
            ])
            ->assertStatus(201);

        // List methods
        $response = $this->actingAsSanctum($user)
            ->getJson('/api/v1/payments/methods');

        $response->assertStatus(200);
        $methods = $response->json('data');
        $this->assertCount(2, $methods);

        // Set second as default
        $secondMethod = PaymentMethod::where('user_id', $user->id)
            ->where('type', 'bank_account')
            ->first();

        $this->actingAsSanctum($user)
            ->putJson("/api/v1/payments/methods/{$secondMethod->id}/default")
            ->assertStatus(200);

        $secondMethod->refresh();
        $this->assertTrue($secondMethod->is_default);

        // Remove first method
        $firstMethod = PaymentMethod::where('user_id', $user->id)
            ->where('type', 'mobile_money')
            ->first();

        $this->actingAsSanctum($user)
            ->deleteJson("/api/v1/payments/methods/{$firstMethod->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('payment_methods', ['id' => $firstMethod->id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 13: Admin Payment Stats
    // ═══════════════════════════════════════════════════════════════════

    public function test_admin_can_view_payment_stats(): void
    {
        $admin = $this->createFinanceAdmin();

        $response = $this->actingAsSanctum($admin)
            ->getJson('/api/v1/admin/payments/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_volume',
                    'total_fees',
                    'total_refunded',
                    'escrow_held',
                    'pending_count',
                    'failed_count',
                    'currency',
                    'by_type',
                ],
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TEST 14: Transaction Export
    // ═══════════════════════════════════════════════════════════════════

    public function test_admin_can_export_transactions(): void
    {
        $admin = $this->createFinanceAdmin();

        $response = $this->actingAsSanctum($admin)
            ->getJson('/api/v1/admin/transactions/export');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
