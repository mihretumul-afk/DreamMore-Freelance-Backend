<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\ContactMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ContactMessageTest
 *
 * Verifies the public Contact form (footer "Contact" button) saves
 * submissions and that the admin Contacts inbox is gated by the
 * contacts.manage permission (granted to Support Admin by default).
 */
class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    private Role $supportAdminRole;
    private Role $disputeAdminRole;

    private User $supportAdmin;
    private User $disputeAdmin;
    private User $freelancer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RbacSeeder'])->assertSuccessful();

        $this->supportAdminRole = Role::where('slug', Role::SUPPORT_ADMIN)->firstOrFail();
        $this->disputeAdminRole = Role::where('slug', Role::DISPUTE_ADMIN)->firstOrFail();

        $this->supportAdmin = $this->makeAdmin('support@test.com', $this->supportAdminRole);
        $this->disputeAdmin = $this->makeAdmin('dispute@test.com', $this->disputeAdminRole);

        $this->freelancer = User::create([
            'name'     => 'Freelancer',
            'email'    => 'fl@test.com',
            'password' => Hash::make('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
    }

    private function makeAdmin(string $email, Role $role): User
    {
        $user = User::create([
            'name'     => 'Test ' . $role->name,
            'email'    => $email,
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);

        $user->adminRoles()->attach($role->id, [
            'assigned_by' => null,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function makeMessage(array $overrides = []): ContactMessage
    {
        return ContactMessage::create(array_merge([
            'name'    => 'Abebe Kebede',
            'email'   => 'abebe@example.com',
            'subject' => 'Need help with my account',
            'message' => 'I cannot withdraw my earnings. Please help.',
        ], $overrides));
    }

    // ── Public Contact form ─────────────────────────────────────────────

    public function test_guest_can_submit_contact_message(): void
    {
        $this->postJson('/api/v1/contact-messages', [
            'name'    => 'Abebe Kebede',
            'email'   => 'abebe@example.com',
            'subject' => 'Payment question',
            'message' => 'When will my payment arrive?',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_messages', [
            'email'   => 'abebe@example.com',
            'subject' => 'Payment question',
            'user_id' => null,
            'status'  => 'new',
        ]);
    }

    public function test_logged_in_user_submission_is_linked_to_account(): void
    {
        $this->actingAs($this->freelancer, 'sanctum')
            ->postJson('/api/v1/contact-messages', [
                'name'    => $this->freelancer->name,
                'email'   => $this->freelancer->email,
                'subject' => 'Verification help',
                'message' => 'My verification was rejected.',
            ])->assertCreated();

        $this->assertDatabaseHas('contact_messages', [
            'email'   => $this->freelancer->email,
            'user_id' => $this->freelancer->id,
        ]);
    }

    public function test_contact_submission_requires_valid_fields(): void
    {
        $this->postJson('/api/v1/contact-messages', [
            'name'  => '',
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    // ── Admin inbox access control ───────────────────────────────────────

    public function test_support_admin_can_list_contact_messages(): void
    {
        $this->makeMessage();
        $this->makeMessage(['subject' => 'Another inquiry']);

        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson('/api/v1/admin/contacts')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total']])
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_without_permission_is_forbidden(): void
    {
        $this->makeMessage();

        // Dispute Admin is not granted contacts.manage by default.
        $this->actingAs($this->disputeAdmin, 'sanctum')
            ->getJson('/api/v1/admin/contacts')
            ->assertForbidden();
    }

    public function test_guest_cannot_access_admin_contacts_inbox(): void
    {
        $this->getJson('/api/v1/admin/contacts')->assertUnauthorized();
    }

    // ── Admin inbox workflow ─────────────────────────────────────────────

    public function test_opening_message_marks_it_as_read(): void
    {
        $message = $this->makeMessage();

        $this->actingAs($this->supportAdmin, 'sanctum')
            ->getJson("/api/v1/admin/contacts/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'read');

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_support_admin_can_resolve_message_with_note(): void
    {
        $message = $this->makeMessage();

        $this->actingAs($this->supportAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/contacts/{$message->id}", [
                'status'     => 'resolved',
                'resolution' => 'Replied by email with withdrawal instructions.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $fresh = $message->fresh();
        $this->assertEquals('resolved', $fresh->status);
        $this->assertEquals('Replied by email with withdrawal instructions.', $fresh->resolution);
        $this->assertEquals($this->supportAdmin->id, $fresh->resolved_by);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_support_admin_can_reopen_a_resolved_message(): void
    {
        $message = $this->makeMessage([
            'status'      => 'resolved',
            'resolution'  => 'All done.',
            'resolved_by' => $this->supportAdmin->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($this->supportAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/contacts/{$message->id}", [
                'status' => 'read',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'read');

        $fresh = $message->fresh();
        $this->assertNull($fresh->resolution);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolved_by);
    }

    public function test_support_admin_can_delete_a_message(): void
    {
        $message = $this->makeMessage();

        $this->actingAs($this->supportAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/contacts/{$message->id}")
            ->assertOk();

        $this->assertDatabaseMissing('contact_messages', ['id' => $message->id]);
    }
}
