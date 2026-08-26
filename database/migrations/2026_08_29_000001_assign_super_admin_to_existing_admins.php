<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The old isSuperAdmin() treated any admin with zero sub-roles as Super Admin.
     * Now that this bypass is removed, we must explicitly assign the super_admin
     * role to existing admin users who currently have NO sub-roles, so they
     * don't lose access.
     */
    public function up(): void
    {
        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->first();

        if (!$superAdminRole) {
            return;
        }

        // Find all admin users who have NO roles assigned yet.
        $adminsNeedingRole = User::where('role', 'admin')
            ->whereDoesntHave('adminRoles')
            ->pluck('id');

        if ($adminsNeedingRole->isEmpty()) {
            return;
        }

        // Assign super_admin role to each.
        $syncData = [
            $superAdminRole->id => [
                'assigned_by' => null,
                'assigned_at' => now(),
            ],
        ];

        foreach ($adminsNeedingRole as $adminId) {
            DB::table('admin_user_roles')->insert([
                'user_id'     => $adminId,
                'role_id'     => $superAdminRole->id,
                'assigned_by' => null,
                'assigned_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Reversing this would remove the super_admin role from admins,
        // which could lock them out. Intentionally left empty.
    }
};
