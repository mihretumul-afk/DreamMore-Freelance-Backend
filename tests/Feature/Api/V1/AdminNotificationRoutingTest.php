<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_reach_only_admins_with_matching_role_permissions(): void
    {
        // 1. Create permissions
        $verifyPerm = Permission::create([
            'slug' => 'users.verify',
            'name' => 'Verify Users',
            'group' => 'users',
        ]);
        $financePerm = Permission::create([
            'slug' => 'finance.view',
            'name' => 'View Finance',
            'group' => 'finance',
        ]);

        // 2. Create roles
        $superAdminRole = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $supportRole = Role::create([
            'slug' => Role::SUPPORT_ADMIN,
            'name' => 'Support Admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $supportRole->permissions()->attach($verifyPerm->id);

        $financeRole = Role::create([
            'slug' => Role::FINANCE_ADMIN,
            'name' => 'Finance Admin',
            'is_system' => true,
            'is_active' => true,
        ]);
        $financeRole->permissions()->attach($financePerm->id);

        // 3. Create admin users
        $superAdminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $superAdminUser->adminRoles()->attach($superAdminRole->id);

        $supportAdminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $supportAdminUser->adminRoles()->attach($supportRole->id);

        $financeAdminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $financeAdminUser->adminRoles()->attach($financeRole->id);

        // 4. Trigger credential notification (users.verify)
        NotificationService::notifyAdmins(
            'users.verify',
            'credential_submitted',
            'New Credential',
            'Test credential submitted'
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $superAdminUser->id,
            'type' => 'credential_submitted',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $supportAdminUser->id,
            'type' => 'credential_submitted',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $financeAdminUser->id,
            'type' => 'credential_submitted',
        ]);

        // 5. Trigger finance notification (finance.view)
        NotificationService::notifyAdmins(
            'finance.view',
            'withdrawal_requested',
            'New Withdrawal',
            'Test withdrawal requested'
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $superAdminUser->id,
            'type' => 'withdrawal_requested',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $financeAdminUser->id,
            'type' => 'withdrawal_requested',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $supportAdminUser->id,
            'type' => 'withdrawal_requested',
        ]);
    }
}
