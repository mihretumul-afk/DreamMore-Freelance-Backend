<?php

namespace Tests\Feature\Api\V1;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $employer;
    private User $freelancer;
    private string $employerToken;
    private string $freelancerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employer = User::create([
            'name'     => 'Test Employer',
            'email'    => 'employer_funds@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $this->employerToken = $this->employer->createToken('employer_token')->plainTextToken;

        $this->freelancer = User::create([
            'name'     => 'Test Freelancer',
            'email'    => 'freelancer_funds@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $this->freelancerToken = $this->freelancer->createToken('freelancer_token')->plainTextToken;

        Wallet::forUser($this->employer->id);
        Wallet::forUser($this->freelancer->id);
    }

    public function test_reconcile_returns_previous_balance_and_completed_status(): void
    {
        $wallet = Wallet::forUser($this->employer->id);
        $wallet->update(['available_balance' => 500.00]);

        $payment = Payment::create([
            'reference'          => 'PAY-TEST-RECONCILE-1',
            'payer_id'           => $this->employer->id,
            'type'               => Payment::TYPE_WALLET_DEPOSIT,
            'amount'             => 250.00,
            'net_amount'         => 250.00,
            'currency'           => 'ETB',
            'status'             => Payment::STATUS_COMPLETED,
            'provider'           => 'sandbox',
            'provider_reference' => 'SANDBOX-1234',
            'processed_at'       => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
            ->postJson("/api/v1/wallet/deposit/reconcile/{$payment->reference}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Funds added successfully.',
            ])
            ->assertJsonPath('data.payment.reference', 'PAY-TEST-RECONCILE-1')
            ->assertJsonPath('data.payment.status', 'completed');

        $this->assertEquals(500.0, (float) $response->json('data.wallet.available_balance'));
        $this->assertEquals(250.0, (float) $response->json('data.previous_balance'));
    }

    public function test_reconcile_all_picks_up_recent_pending_deposits(): void
    {
        $payment = Payment::create([
            'reference'          => 'PAY-RECENT-PENDING-1',
            'payer_id'           => $this->freelancer->id,
            'type'               => Payment::TYPE_WALLET_DEPOSIT,
            'amount'             => 100.00,
            'net_amount'         => 100.00,
            'currency'           => 'ETB',
            'status'             => Payment::STATUS_PENDING,
            'provider'           => 'sandbox',
            'provider_reference' => 'SANDBOX-PENDING-1',
            'created_at'         => now()->subMinute(), // Created 1 min ago (recent)
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
            ->postJson('/api/v1/wallet/deposit/reconcile-all');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Reconciliation complete.',
            ]);
    }
}
