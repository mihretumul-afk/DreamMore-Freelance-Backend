<?php

namespace Tests\Traits;

use App\Models\Role;
use App\Models\User;

/**
 * Provides a helper to create an admin user with the super_admin role
 * for use in tests. After removing the backward-compatible bootstrap
 * bypass in isSuperAdmin(), tests must explicitly assign the super_admin
 * role to admin users that need full access.
 */
trait CreatesSuperAdmin
{
    /**
     * Create an admin user and assign the super_admin role.
     */
    protected function createSuperAdmin(string $email = 'admin@test.com'): User
    {
        $admin = User::create([
            'name'     => 'Admin User',
            'email'    => $email,
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);

        $superAdminRole = Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );

        $admin->adminRoles()->attach($superAdminRole->id);

        return $admin;
    }
}
