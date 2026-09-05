<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PlatformDeposit;
use App\Models\PlatformWithdrawal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep payment tests hermetic — never call the real Chapa API.
        config(['payment.default' => 'sandbox']);

        $this->admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        // Super admin role bypasses the permission middleware in tests.
        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken];
    }

    public function test_non_admin_cannot_manage_platform_funds(): void
    {
        $employer = User::create([
            'name'     => 'Employer',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $token = $employer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/admin/finance/add-funds', [
                'amount'      => 100,
                'description' => 'test',
            ])
            ->assertStatus(403);
    }

    public function test_add_funds_credits_platform_balance_in_sandbox(): void
    {
        // Sandbox provider confirms synchronously → deposit completes immediately.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/admin/finance/add-funds', [
                'amount'      => 1500,
                'description' => 'Operational deposit',
                'source'      => 'Test bank transfer',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'deposit' => ['status' => 'completed'],
                ],
            ]);

        $this->assertDatabaseHas('platform_deposits', [
            'amount'   => 1500,
            'status'   => PlatformDeposit::STATUS_COMPLETED,
            'provider' => 'sandbox',
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/admin/finance/platform-revenue')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'total_deposits'    => 1500,
                    'total_withdrawn'   => 0,
                    'available_revenue' => 1500,
                ],
            ]);
    }

    public function test_withdraw_revenue_decreases_platform_balance(): void
    {
        // Seed the platform balance with a completed deposit.
        PlatformDeposit::create([
            'reference'     => 'PLAT-DEP-SEED123',
            'amount'        => 2000,
            'currency'      => 'ETB',
            'status'        => PlatformDeposit::STATUS_COMPLETED,
            'description'   => 'Seeded deposit',
            'source'        => 'Test',
            'provider'      => 'sandbox',
            'deposited_by'  => $this->admin->id,
            'completed_at'  => now(),
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/admin/finance/withdraw-revenue', [
                'amount'         => 800,
                'account_name'   => 'DreamMore Technologies PLC',
                'account_number' => '1000123456789',
                'bank_name'      => 'Commercial Bank of Ethiopia',
                'bank_code'      => 'CBE',
                'description'    => 'Monthly revenue payout',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'withdrawal'        => ['status' => 'completed'],
                    'available_revenue' => 1200,
                ],
            ]);

        $this->assertDatabaseHas('platform_withdrawals', [
            'amount'   => 800,
            'status'   => PlatformWithdrawal::STATUS_COMPLETED,
            'provider' => 'sandbox',
        ]);

        // Balance reflects: 2000 deposited − 800 withdrawn = 1200 available.
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/admin/finance/platform-revenue')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'total_deposits'    => 2000,
                    'total_withdrawn'   => 800,
                    'available_revenue' => 1200,
                ],
            ]);
    }

    public function test_withdraw_revenue_rejected_when_insufficient_balance(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/admin/finance/withdraw-revenue', [
                'amount'         => 500,
                'account_name'   => 'DreamMore Technologies PLC',
                'account_number' => '1000123456789',
                'bank_name'      => 'Commercial Bank of Ethiopia',
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_reconcile_all_credits_balance_for_stuck_pending_deposit(): void
    {
        // Simulate a Chapa deposit that was paid but whose webhook was missed:
        // pending with a provider reference older than 2 minutes.
        $pending = PlatformDeposit::create([
            'reference'          => 'PLAT-DEP-PEND123',
            'amount'             => 750,
            'currency'           => 'ETB',
            'status'             => PlatformDeposit::STATUS_PENDING,
            'description'        => 'Chapa deposit',
            'source'             => 'Chapa deposit',
            'provider'           => 'sandbox',
            'provider_reference' => 'PLAT-DEP-PEND123',
            'deposited_by'       => $this->admin->id,
        ]);
        // created_at is not fillable — backdate it directly so it passes the
        // 2-minute reconciliation age check.
        $pending->created_at = now()->subMinutes(5);
        $pending->save();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/admin/finance/add-funds/reconcile-all')
            ->assertStatus(200);

        $this->assertDatabaseHas('platform_deposits', [
            'id'     => $pending->id,
            'status' => PlatformDeposit::STATUS_COMPLETED,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/admin/finance/platform-revenue')
            ->assertStatus(200)
            ->assertJsonPath('data.available_revenue', 750);
    }
}
