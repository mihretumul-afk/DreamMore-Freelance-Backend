<?php

namespace Tests\Feature\Api\V1;

use App\Models\Payment;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialHistoryDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'freelancer', string $email = 'user@test.com'): User
    {
        return User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $email,
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'active',
        ]);
    }

    private function createWallet(User $user, float $balance = 1000): Wallet
    {
        return Wallet::create([
            'user_id'           => $user->id,
            'available_balance' => $balance,
            'pending_balance'   => 0,
            'held_balance'      => 0,
            'total_earned'      => 0,
            'total_withdrawn'   => 0,
            'currency'          => 'ETB',
        ]);
    }

    private function createTransaction(User $user, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'reference'  => 'TXN-' . strtoupper(uniqid()),
            'user_id'    => $user->id,
            'wallet_id'  => $this->createWallet($user)->id,
            'direction'  => Transaction::DIR_DEBIT,
            'type'       => Transaction::TYPE_FUNDS_HELD,
            'amount'     => 500,
            'balance_before' => 0,
            'balance_after'  => 0,
            'currency'   => 'ETB',
            'status'     => Transaction::STATUS_COMPLETED,
            'description' => 'Funds held in escrow',
        ], $overrides));
    }

    public function test_user_can_delete_own_transaction_history_entry(): void
    {
        $user = $this->createUser();
        $tx = $this->createTransaction($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $tx->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/transactions/{$tx->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Hidden from the history list, but the financial row is only soft-deleted.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $tx->id]);

        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
    }

    public function test_user_can_delete_virtual_payment_entry_and_it_stays_hidden(): void
    {
        $user = $this->createUser('employer', 'employer@test.com');

        // Payment without any ledger transaction row → shows up as a virtual entry.
        $payment = Payment::create([
            'reference'     => 'PAY-' . strtoupper(uniqid()),
            'payer_id'      => $user->id,
            'type'          => Payment::TYPE_WALLET_DEPOSIT,
            'amount'        => 250,
            'platform_fee'  => 0,
            'processing_fee' => 0,
            'fee'           => 0,
            'net_amount'    => 250,
            'currency'      => 'ETB',
            'status'        => Payment::STATUS_COMPLETED,
            'description'   => 'Wallet deposit',
        ]);

        $virtualId = 'virtual_' . $payment->id;

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $virtualId]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/transactions/{$virtualId}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // The payment-derived entry must not resurface when the list is rebuilt.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/transactions')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $virtualId]);

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_user_cannot_delete_another_users_transaction(): void
    {
        $owner = $this->createUser('employer', 'owner@test.com');
        $other = $this->createUser('employer', 'other@test.com');
        $tx = $this->createTransaction($owner);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/transactions/{$tx->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'deleted_at' => null]);
    }

    public function test_user_can_delete_completed_withdrawal_history_entry(): void
    {
        $user = $this->createUser();
        $wallet = $this->createWallet($user);

        $withdrawal = Withdrawal::create([
            'reference'  => 'WTH-' . strtoupper(uniqid()),
            'user_id'    => $user->id,
            'amount'     => 300,
            'fee'        => 4.5,
            'net_amount' => 295.5,
            'currency'   => 'ETB',
            'status'     => Withdrawal::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/withdrawals/{$withdrawal->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/withdrawals')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $withdrawal->id]);

        $this->assertSoftDeleted('withdrawals', ['id' => $withdrawal->id]);
    }

    public function test_active_withdrawal_cannot_be_deleted(): void
    {
        $user = $this->createUser();
        $this->createWallet($user);

        $withdrawal = Withdrawal::create([
            'reference'  => 'WTH-' . strtoupper(uniqid()),
            'user_id'    => $user->id,
            'amount'     => 300,
            'fee'        => 4.5,
            'net_amount' => 295.5,
            'currency'   => 'ETB',
            'status'     => Withdrawal::STATUS_REQUESTED,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/withdrawals/{$withdrawal->id}")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_payment_and_withdrawal_from_finance_history(): void
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $admin->adminRoles()->attach($superAdminRole->id);

        $freelancer = $this->createUser('freelancer', 'freelancer@test.com');
        $this->createWallet($freelancer);

        $payment = Payment::create([
            'reference'     => 'PAY-' . strtoupper(uniqid()),
            'payer_id'      => $freelancer->id,
            'type'          => Payment::TYPE_WALLET_DEPOSIT,
            'amount'        => 500,
            'platform_fee'  => 0,
            'processing_fee' => 0,
            'fee'           => 0,
            'net_amount'    => 500,
            'currency'      => 'ETB',
            'status'        => Payment::STATUS_COMPLETED,
            'description'   => 'Wallet deposit',
        ]);

        $withdrawal = Withdrawal::create([
            'reference'  => 'WTH-' . strtoupper(uniqid()),
            'user_id'    => $freelancer->id,
            'amount'     => 200,
            'fee'        => 3,
            'net_amount' => 197,
            'currency'   => 'ETB',
            'status'     => Withdrawal::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/finance/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/finance/withdrawals/{$withdrawal->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('payments', ['id' => $payment->id]);
        $this->assertSoftDeleted('withdrawals', ['id' => $withdrawal->id]);

        // Both are gone from the admin finance lists.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/payments')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $payment->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/withdrawals')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $withdrawal->id]);
    }
}
