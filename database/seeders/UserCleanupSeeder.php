<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Clean the database down to exactly 3 users per role.
 *
 * Strategy:
 *  1. For each role (admin, employer, freelancer) sort users by ID ASC.
 *  2. Keep the first 3 — these are the original seeded / earliest-created accounts.
 *  3. Delete the rest (cascade handles related records).
 *  4. If fewer than 3 admins exist, create placeholder admin accounts.
 */
class UserCleanupSeeder extends Seeder
{
    /** Shared development password for any newly-created placeholder accounts. */
    private const DEV_PASSWORD = 'DreamMore@2026';

    private const KEEP_PER_ROLE = 3;

    public function run(): void
    {
        // Ensure RBAC roles & permissions exist before cleanup
        $this->call(RbacSeeder::class);

        $this->cleanFreelancers();
        $this->cleanEmployers();
        $this->cleanAdmins();
        $this->ensureSuperAdminRole();

        // Final verification
        $adminCount    = User::where('role', 'admin')->count();
        $freelCount    = User::where('role', 'freelancer')->count();
        $employerCount = User::where('role', 'employer')->count();

        $this->command->info("Cleanup complete — Admins: $adminCount, Freelancers: $freelCount, Employers: $employerCount");
    }

    /* ------------------------------------------------------------------ */
    /*  Ensure Super Admin has RBAC role                                   */
    /* ------------------------------------------------------------------ */
    private function ensureSuperAdminRole(): void
    {
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();
        if (!$superAdminRole) {
            $this->command->warn('Super Admin role not found — skipping RBAC assignment.');
            return;
        }

        // Assign Super Admin role to admin@dreammore.com
        $adminUser = User::where('email', 'admin@dreammore.com')->first();
        if ($adminUser) {
            $adminUser->adminRoles()->syncWithoutDetaching([
                $superAdminRole->id => [
                    'assigned_by' => null,
                    'assigned_at' => now(),
                ],
            ]);
            $this->command->info('Assigned Super Admin RBAC role to admin@dreammore.com');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Freelancers                                                        */
    /* ------------------------------------------------------------------ */
    private function cleanFreelancers(): void
    {
        $keep    = User::where('role', 'freelancer')->orderBy('id')->limit(self::KEEP_PER_ROLE)->pluck('id');
        $removal = User::where('role', 'freelancer')
            ->whereNotIn('id', $keep)
            ->get();

        if ($removal->isEmpty()) {
            $this->command->info('Freelancers: already at target count — nothing to remove.');
            return;
        }

        $this->command->info('Removing ' . $removal->count() . ' excess freelancer(s)…');

        DB::beginTransaction();
        try {
            foreach ($removal as $user) {
                $this->deleteUser($user);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->command->info('Freelancers kept: ' . $keep->implode(', '));
    }

    /* ------------------------------------------------------------------ */
    /*  Employers                                                          */
    /* ------------------------------------------------------------------ */
    private function cleanEmployers(): void
    {
        $keep    = User::where('role', 'employer')->orderBy('id')->limit(self::KEEP_PER_ROLE)->pluck('id');
        $removal = User::where('role', 'employer')
            ->whereNotIn('id', $keep)
            ->get();

        if ($removal->isEmpty()) {
            $this->command->info('Employers: already at target count — nothing to remove.');
            return;
        }

        $this->command->info('Removing ' . $removal->count() . ' excess employer(s)…');

        DB::beginTransaction();
        try {
            foreach ($removal as $user) {
                $this->deleteUser($user);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->command->info('Employers kept: ' . $keep->implode(', '));
    }

    /* ------------------------------------------------------------------ */
    /*  Administrators                                                     */
    /* ------------------------------------------------------------------ */
    private function cleanAdmins(): void
    {
        $existing = User::where('role', 'admin')->orderBy('id')->get();

        // Remove excess admins (keep first KEEP_PER_ROLE)
        if ($existing->count() > self::KEEP_PER_ROLE) {
            $keep    = $existing->take(self::KEEP_PER_ROLE)->pluck('id');
            $removal = $existing->whereNotIn('id', $keep);

            $this->command->info('Removing ' . $removal->count() . ' excess admin(s)…');

            DB::beginTransaction();
            try {
                foreach ($removal as $user) {
                    $this->deleteUser($user);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $this->command->info('Admins kept: ' . $keep->implode(', '));
        }

        // Create placeholder admins if fewer than 3 exist
        $currentCount = User::where('role', 'admin')->count();
        if ($currentCount < self::KEEP_PER_ROLE) {
            $needed = self::KEEP_PER_ROLE - $currentCount;
            $this->command->info("Only $currentCount admin(s) exist — creating $needed placeholder admin(s)…");

            $placeholders = [
                [
                    'name'  => 'Support Admin',
                    'email' => 'support.admin@dreammore.com',
                ],
                [
                    'name'  => 'Finance Admin',
                    'email' => 'finance.admin@dreammore.com',
                ],
            ];

            $existingEmails = User::where('role', 'admin')->pluck('email')->toArray();
            $created = 0;

            foreach ($placeholders as $def) {
                if ($created >= $needed) {
                    break;
                }
                if (in_array($def['email'], $existingEmails)) {
                    continue;
                }

                DB::beginTransaction();
                try {
                    $user = User::create([
                        'name'     => $def['name'],
                        'email'    => $def['email'],
                        'password' => Hash::make(self::DEV_PASSWORD),
                        'role'     => 'admin',
                        'status'   => 'active',
                    ]);

                    // Assign Support Admin role as default
                    $supportRole = Role::where('slug', Role::SUPPORT_ADMIN)->first();
                    if ($supportRole) {
                        $user->adminRoles()->syncWithoutDetaching([
                            $supportRole->id => [
                                'assigned_by' => null,
                                'assigned_at' => now(),
                            ],
                        ]);
                    }

                    DB::commit();
                    $created++;
                    $this->command->info("Created admin: {$def['email']}");
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->command->warn("Failed to create {$def['email']}: " . $e->getMessage());
                }
            }
        }

        $finalCount = User::where('role', 'admin')->count();
        $this->command->info("Final admin count: $finalCount");
    }

    /* ------------------------------------------------------------------ */
    /*  Shared deletion helper                                             */
    /* ------------------------------------------------------------------ */
    private function deleteUser(User $user): void
    {
        $email = $user->email;
        $role  = $user->role;

        // The User model's boot() deleting callback handles:
        //   - jobs, freelancer profiles, employer profiles, proposals
        //   - saved jobs/freelancers, credentials, verifications
        //   - portfolio items, notifications, tokens, admin role assignments
        //
        // FK cascadeOnDelete handles the rest (messages, contracts,
        // milestones, reviews, reports, etc.)
        //
        // audit_logs uses nullOnDelete for actor_id — log entries are
        // preserved but the actor reference is nulled out.

        $user->delete();

        $this->command->line("  ✔ Deleted {$role}: {$email}");
    }
}
