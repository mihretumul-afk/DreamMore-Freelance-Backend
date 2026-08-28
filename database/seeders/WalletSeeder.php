<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    /**
     * Seed wallets for existing test users.
     */
    public function run(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $balance = $user->role === 'employer' ? 10000.00 : 0.00;

            Wallet::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'available_balance' => $balance,
                    'pending_balance' => 0.00,
                    'currency' => 'ETB',
                ]
            );
        }
    }
}
