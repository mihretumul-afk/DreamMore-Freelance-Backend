<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'freelancer'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => "{$role}_" . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => $role,
        ]);
    }

    public function test_notification_list_works(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'New Proposal',
            'message' => 'You have a new proposal.',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_unauthenticated_user_cannot_list_notifications(): void
    {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertUnauthorized();
    }

    public function test_mark_notification_as_read_works(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'New Proposal',
            'message' => 'You have a new proposal.',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_notifications_as_read(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'Proposal 1',
            'message' => 'Message 1',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'contract_created',
            'title' => 'Contract Created',
            'message' => 'Message 2',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/notifications/read-all');

        $response->assertOk();

        $unreadCount = Notification::where('user_id', $user->id)->whereNull('read_at')->count();
        $this->assertEquals(0, $unreadCount);
    }

    public function test_unread_count_works(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'Notification 1',
            'message' => 'Message 1',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'message_received',
            'title' => 'Notification 2',
            'message' => 'Message 2',
            'read_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/unread');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['unread_count' => 1],
            ]);
    }

    public function test_user_only_sees_own_notifications(): void
    {
        $user1 = $this->createUser();
        $user2 = $this->createUser();
        $token = $user1->createToken('test')->plainTextToken;

        Notification::create([
            'user_id' => $user2->id,
            'type' => 'new_proposal',
            'title' => 'Other user notification',
            'message' => 'This should not appear.',
        ]);

        Notification::create([
            'user_id' => $user1->id,
            'type' => 'new_proposal',
            'title' => 'My notification',
            'message' => 'This should appear.',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications');

        $response->assertOk();

        $notifications = $response->json('data');
        foreach ($notifications as $n) {
            $this->assertEquals($user1->id, $n['user_id']);
        }
    }

    private function createAdminWithRole(string $roleSlug): User
    {
        $this->seed(RbacSeeder::class);

        $user = $this->createUser('admin');
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->adminRoles()->attach($role->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    public function test_finance_notification_only_reaches_finance_and_super_admins(): void
    {
        $superAdmin = $this->createAdminWithRole(Role::SUPER_ADMIN);
        $financeAdmin = $this->createAdminWithRole(Role::FINANCE_ADMIN);
        $supportAdmin = $this->createAdminWithRole(Role::SUPPORT_ADMIN);
        $disputeAdmin = $this->createAdminWithRole(Role::DISPUTE_ADMIN);

        NotificationService::notifyAdmins(
            'finance.view',
            'withdrawal_requested',
            'Withdrawal Requested',
            'A user requested a withdrawal.'
        );

        // Super Admin and Finance Admin receive it
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'withdrawal_requested']);
        $this->assertDatabaseHas('notifications', ['user_id' => $financeAdmin->id, 'type' => 'withdrawal_requested']);

        // Support and Dispute admins do NOT
        $this->assertDatabaseMissing('notifications', ['user_id' => $supportAdmin->id, 'type' => 'withdrawal_requested']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $disputeAdmin->id, 'type' => 'withdrawal_requested']);
    }

    public function test_verification_notification_reaches_support_admins_only(): void
    {
        $superAdmin = $this->createAdminWithRole(Role::SUPER_ADMIN);
        $financeAdmin = $this->createAdminWithRole(Role::FINANCE_ADMIN);
        $supportAdmin = $this->createAdminWithRole(Role::SUPPORT_ADMIN);

        NotificationService::notifyAdmins(
            'users.verify',
            'credential_submitted',
            'New Credential Submitted',
            'A user submitted a credential for review.'
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'credential_submitted']);
        $this->assertDatabaseHas('notifications', ['user_id' => $supportAdmin->id, 'type' => 'credential_submitted']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $financeAdmin->id, 'type' => 'credential_submitted']);
    }

    public function test_admin_without_role_receives_no_admin_notifications(): void
    {
        $this->seed(RbacSeeder::class);
        $rolelessAdmin = $this->createUser('admin');
        $financeAdmin = $this->createAdminWithRole(Role::FINANCE_ADMIN);

        NotificationService::notifyAdmins(
            'finance.view',
            'withdrawal_requested',
            'Withdrawal Requested',
            'A user requested a withdrawal.'
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $financeAdmin->id, 'type' => 'withdrawal_requested']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $rolelessAdmin->id, 'type' => 'withdrawal_requested']);
    }

    public function test_notifications_can_be_filtered_by_read_status(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'Unread',
            'message' => 'Unread notification',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'contract_created',
            'title' => 'Read',
            'message' => 'Read notification',
            'read_at' => now(),
        ]);

        // Filter unread
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications?read=false');

        $response->assertOk();

        // Filter read
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications?read=true');

        $response->assertOk();
    }

    public function test_user_can_delete_own_notification(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'new_proposal',
            'title' => 'To be deleted',
            'message' => 'Delete me',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/notifications/{$notification->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $user1 = $this->createUser();
        $user2 = $this->createUser();
        $token2 = $user2->createToken('test')->plainTextToken;

        $notification = Notification::create([
            'user_id' => $user1->id,
            'type' => 'new_proposal',
            'title' => 'User1 notification',
            'message' => 'User2 cannot delete this',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->deleteJson("/api/v1/notifications/{$notification->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_mark_as_read_keeps_notification_in_list_and_updates_unread_count(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'contract_created',
            'title' => 'Contract Notice',
            'message' => 'Contract was created',
            'read_at' => null,
        ]);

        // Verify unread count is 1
        $countRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/unread');
        $countRes->assertOk()->assertJsonPath('data.unread_count', 1);

        // Mark as read
        $readRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/notifications/{$notification->id}/read");
        $readRes->assertOk();

        // Verify unread count is now 0
        $countResAfter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/unread');
        $countResAfter->assertOk()->assertJsonPath('data.unread_count', 0);

        // Verify notification is STILL in notification list/history
        $listRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications');
        $listRes->assertOk();
        $this->assertCount(1, $listRes->json('data'));
        $this->assertEquals($notification->id, $listRes->json('data.0.id'));
        $this->assertNotNull($listRes->json('data.0.read_at'));
    }
}
