<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    /**
     * All 35 platform permissions, grouped by domain.
     * [slug => [name, group, description]]
     */
    private array $permissions = [
        // ── Users ───────────────────────────────────────────────────────
        'users.view'     => ['View Users',     'users', 'Browse and search platform users'],
        'users.edit'     => ['Edit Users',     'users', 'Update user profile information'],
        'users.suspend'  => ['Suspend Users',  'users', 'Suspend an active user account'],
        'users.activate' => ['Activate Users', 'users', 'Reactivate a suspended user account'],
        'users.verify'   => ['Verify Users',   'users', 'Approve or reject identity verifications'],

        // ── Jobs ─────────────────────────────────────────────────────────
        'jobs.view'     => ['View Jobs',     'jobs', 'Browse all marketplace job postings'],
        'jobs.create'   => ['Create Jobs',   'jobs', 'Post new jobs on behalf of employers'],
        'jobs.edit'     => ['Edit Jobs',     'jobs', 'Modify existing job postings'],
        'jobs.approve'  => ['Approve Jobs',  'jobs', 'Approve draft or flagged job postings'],
        'jobs.reject'   => ['Reject Jobs',   'jobs', 'Reject inappropriate job postings'],
        'jobs.delete'   => ['Delete Jobs',   'jobs', 'Permanently remove job postings'],
        'jobs.moderate' => ['Moderate Jobs', 'jobs', 'Change the status of any job posting'],

        // ── Payments ─────────────────────────────────────────────────────
        'payments.view'    => ['View Payments',    'payments', 'View payment records and history'],
        'payments.verify'  => ['Verify Payments',  'payments', 'Mark payments as verified'],
        'payments.process' => ['Process Payments', 'payments', 'Initiate or approve payment processing'],
        'payments.refund'  => ['Refund Payments',  'payments', 'Issue refunds to users'],

        // ── Milestones ────────────────────────────────────────────────────
        'milestones.view'    => ['View Milestones',    'milestones', 'View milestone details and submissions'],
        'milestones.create'  => ['Create Milestones',  'milestones', 'Create milestones on contracts'],
        'milestones.fund'    => ['Fund Milestones',    'milestones', 'Allocate funds to milestones'],
        'milestones.release' => ['Release Milestones', 'milestones', 'Release milestone payments to freelancers'],

        // ── Transactions ──────────────────────────────────────────────────
        'transactions.view'   => ['View Transactions',   'transactions', 'View transaction history'],
        'transactions.export' => ['Export Transactions', 'transactions', 'Export transaction data to CSV/Excel'],

        // ── Disputes ──────────────────────────────────────────────────────
        'disputes.view'     => ['View Disputes',     'disputes', 'View reported disputes and reports'],
        'disputes.review'   => ['Review Disputes',   'disputes', 'Review and investigate disputes'],
        'disputes.resolve'  => ['Resolve Disputes',  'disputes', 'Mark disputes as resolved'],
        'disputes.escalate' => ['Escalate Disputes', 'disputes', 'Escalate disputes to Super Admin'],

        // ── Administrators ────────────────────────────────────────────────
        'admins.view'        => ['View Admins',        'admins', 'List and view admin user accounts'],
        'admins.create'      => ['Create Admins',      'admins', 'Create new admin user accounts'],
        'admins.edit'        => ['Edit Admins',        'admins', 'Update admin user details'],
        'admins.activate'    => ['Activate Admins',    'admins', 'Reactivate a suspended admin'],
        'admins.deactivate'  => ['Deactivate Admins',  'admins', 'Suspend an admin account'],
        'admins.assign_role' => ['Assign Admin Roles', 'admins', 'Assign or revoke admin roles from users'],

        // ── Roles ─────────────────────────────────────────────────────────
        'roles.view'   => ['View Roles',   'roles', 'List and inspect admin roles'],
        'roles.create' => ['Create Roles', 'roles', 'Create new custom admin roles'],
        'roles.edit'   => ['Edit Roles',   'roles', 'Rename or update custom roles'],
        'roles.delete' => ['Delete Roles', 'roles', 'Delete custom (non-system) roles'],
        'roles.assign' => ['Assign Roles', 'roles', 'Assign permissions to roles'],

        // ── Audit ─────────────────────────────────────────────────────────
        'audit_logs.view' => ['View Audit Logs', 'audit', 'Read the platform audit log'],

        // ── Settings ──────────────────────────────────────────────────────
        'settings.view' => ['View Settings', 'settings', 'View platform configuration'],
        'settings.edit' => ['Edit Settings', 'settings', 'Modify platform configuration'],

        // ── Admin Account Security ──────────────────────────────────────
        'admin_account.view'            => ['View Admin Account Settings', 'admin_account', 'View own account and security settings'],
        'admin_account.update_email'    => ['Update Admin Email', 'admin_account', 'Change own email address'],
        'admin_account.change_password' => ['Change Admin Password', 'admin_account', 'Change own account password'],
    ];

    /**
     * System role definitions.
     * [slug => [name, description, permissions[]]]
     */
    private array $roles = [
        Role::SUPER_ADMIN => [
            'name'        => 'Super Admin',
            'description' => 'Unrestricted access to every platform capability. Can create and manage all other admins and roles.',
            // Super Admin gets every permission — assigned dynamically below.
            'permissions' => '*',
        ],

        Role::SUPPORT_ADMIN => [
            'name'        => 'Support Admin',
            'description' => 'Handles user support: views users, verifications, disputes, and jobs. Cannot access finance or admin management.',
            'permissions' => [
                'users.view',
                'users.edit',
                'users.suspend',
                'users.activate',
                'users.verify',
                'jobs.view',
                'jobs.moderate',
                'jobs.reject',
                'disputes.view',
                'disputes.review',
                'disputes.resolve',
                'audit_logs.view',
                'settings.view',
                'admin_account.view',
                'admin_account.change_password',
            ],
        ],

        Role::FINANCE_ADMIN => [
            'name'        => 'Finance Admin',
            'description' => 'Manages payments, milestones, and transactions. Cannot manage admins or roles.',
            'permissions' => [
                'users.view',
                'payments.view',
                'payments.verify',
                'payments.process',
                'payments.refund',
                'milestones.view',
                'milestones.fund',
                'milestones.release',
                'transactions.view',
                'transactions.export',
                'audit_logs.view',
                'settings.view',
                'admin_account.view',
                'admin_account.change_password',
            ],
        ],

        Role::DISPUTE_ADMIN => [
            'name'        => 'Dispute Admin',
            'description' => 'Specialises in dispute resolution. Can review, resolve and escalate disputes. Cannot manage admins, roles, or finance.',
            'permissions' => [
                'users.view',
                'jobs.view',
                'milestones.view',
                'disputes.view',
                'disputes.review',
                'disputes.resolve',
                'disputes.escalate',
                'audit_logs.view',
                'settings.view',
                'admin_account.view',
                'admin_account.change_password',
            ],
        ],
    ];

    public function run(): void
    {
        // 1. Upsert all permissions.
        $permissionModels = [];
        foreach ($this->permissions as $slug => [$name, $group, $description]) {
            $permissionModels[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                compact('name', 'group', 'description')
            );
        }

        $allPermissionIds = collect($permissionModels)->pluck('id')->all();

        // 2. Upsert system roles and sync their permissions.
        foreach ($this->roles as $slug => $def) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'is_system'   => true,
                    'is_active'   => true,
                ]
            );

            if ($def['permissions'] === '*') {
                // Super Admin: all permissions, no granted_by (system seed).
                $syncData = [];
                foreach ($allPermissionIds as $pid) {
                    $syncData[$pid] = ['granted_by' => null, 'granted_at' => now()];
                }
                $role->permissions()->sync($syncData);
            } else {
                $ids = collect($def['permissions'])
                    ->map(fn ($s) => $permissionModels[$s]->id ?? null)
                    ->filter()
                    ->all();

                $syncData = [];
                foreach ($ids as $pid) {
                    $syncData[$pid] = ['granted_by' => null, 'granted_at' => now()];
                }
                $role->permissions()->sync($syncData);
            }
        }

        // 3. Assign the Super Admin role to the seeded admin@dreammore.com user (if present).
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();
        $adminUser = User::where('email', 'admin@dreammore.com')->first();

        if ($superAdminRole && $adminUser) {
            $adminUser->adminRoles()->syncWithoutDetaching([
                $superAdminRole->id => [
                    'assigned_by' => null,
                    'assigned_at' => now(),
                ],
            ]);
        }

        $this->command->info('RBAC seeder complete: ' . count($this->permissions) . ' permissions, ' . count($this->roles) . ' system roles.');
    }
}
