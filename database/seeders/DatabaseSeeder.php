<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's development database.
     *
     * Order matters: categories → users (profiles + freelancer skills) → jobs.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            UserSeeder::class,
            JobSeeder::class,
            RbacSeeder::class,
        ]);

        // Ensure super admin always exists and has correct credentials
        User::updateOrCreate(
            ['email' => 'admin@dreammore.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('Admin123'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );
    }
}