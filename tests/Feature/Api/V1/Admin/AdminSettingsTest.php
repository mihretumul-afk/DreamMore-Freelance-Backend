<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employer;
    private User $freelancer;
    private string $adminToken;
    private string $employerToken;
    private string $freelancerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@test.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'status'   => 'active',
        ]);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        // Assign super_admin role so the admin has full access in tests.
        $superAdminRole = \App\Models\Role::firstOrCreate(
            ['slug' => \App\Models\Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]
        );
        $this->admin->adminRoles()->attach($superAdminRole->id);
        $this->employer = User::create([
            'name'     => 'Employer User',
            'email'    => 'employer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'employer',
            'status'   => 'active',
        ]);
        $this->employerToken = $this->employer->createToken('employer_token')->plainTextToken;

        $this->freelancer = User::create([
            'name'     => 'Freelancer User',
            'email'    => 'freelancer@test.com',
            'password' => bcrypt('password'),
            'role'     => 'freelancer',
            'status'   => 'active',
        ]);
        $this->freelancerToken = $this->freelancer->createToken('freelancer_token')->plainTextToken;
    }

    // ─── ACCESS CONTROL ────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_fetch_settings(): void
    {
        $this->getJson('/api/v1/admin/settings')
             ->assertStatus(401);
    }

    public function test_employer_cannot_fetch_settings(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
             ->getJson('/api/v1/admin/settings')
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    public function test_freelancer_cannot_fetch_settings(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->getJson('/api/v1/admin/settings')
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    public function test_employer_cannot_update_settings(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->employerToken)
             ->putJson('/api/v1/admin/settings', [
                 'platform_name' => 'Hacked Name',
             ])
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    public function test_freelancer_cannot_update_settings(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->freelancerToken)
             ->putJson('/api/v1/admin/settings', [
                 'platform_name' => 'Hacked Name',
             ])
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    // ─── FETCH SETTINGS ────────────────────────────────────────────────

    public function test_admin_can_fetch_settings(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Settings retrieved successfully.',
                 ])
                 ->assertJsonStructure([                         'data' => [
                         'general' => ['platform_name', 'platform_description', 'contact_email'],
                         'payments' => ['default_currency', 'min_proposal_amount', 'max_proposal_amount'],
                         'access' => ['registration_open', 'maintenance_mode'],
                     ],
                 ]);
    }

    public function test_fetch_settings_returns_default_values_when_empty(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Dream More AppWorks', $data['general']['platform_name']);
        $this->assertEquals('ETB', $data['payments']['default_currency']);
        $this->assertTrue($data['access']['registration_open']);
        $this->assertFalse($data['access']['maintenance_mode']);
    }

    public function test_fetch_settings_returns_stored_values(): void
    {
        AdminSetting::setValue('platform_name', 'Custom Platform', 'string');
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Custom Platform', $data['general']['platform_name']);
        $this->assertTrue($data['access']['maintenance_mode']);
    }

    // ─── UPDATE SETTINGS ───────────────────────────────────────────────

    public function test_admin_can_update_single_setting(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'platform_name' => 'Dream More AppWorks v2',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.general.platform_name', 'Dream More AppWorks v2');

        $this->assertDatabaseHas('admin_settings', [
            'key'   => 'platform_name',
            'value' => 'Dream More AppWorks v2',
        ]);
    }

    public function test_admin_can_update_multiple_settings(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'platform_name'        => 'New Name',
                             'contact_email'        => 'new@example.com',
                             'default_currency'     => 'USD',
                             'registration_open'    => 'false',
                             'maintenance_mode'     => 'true',
                         ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('New Name', $data['general']['platform_name']);
        $this->assertEquals('new@example.com', $data['general']['contact_email']);
        $this->assertEquals('USD', $data['payments']['default_currency']);
        $this->assertFalse($data['access']['registration_open']);
        $this->assertTrue($data['access']['maintenance_mode']);

        $this->assertDatabaseHas('admin_settings', ['key' => 'platform_name', 'value' => 'New Name']);
        $this->assertDatabaseHas('admin_settings', ['key' => 'contact_email', 'value' => 'new@example.com']);
        $this->assertDatabaseHas('admin_settings', ['key' => 'default_currency', 'value' => 'USD']);
    }

    public function test_admin_can_update_all_settings(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'platform_name'        => 'Full Update',
                             'platform_description' => 'A new description.',
                             'contact_email'        => 'updated@dm.com',
                             'default_currency'     => 'USD',
                             'min_proposal_amount'  => '100',
                             'max_proposal_amount'  => '50000',
                             'registration_open'    => 'false',
                             'maintenance_mode'     => 'true',
                         ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Full Update', $data['general']['platform_name']);
        $this->assertEquals('A new description.', $data['general']['platform_description']);
        $this->assertEquals('updated@dm.com', $data['general']['contact_email']);
        $this->assertEquals('USD', $data['payments']['default_currency']);
        $this->assertEquals('100', $data['payments']['min_proposal_amount']);
        $this->assertEquals('50000', $data['payments']['max_proposal_amount']);
        $this->assertFalse($data['access']['registration_open']);
        $this->assertTrue($data['access']['maintenance_mode']);
    }

    public function test_admin_can_store_additional_contact_entries(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'contact_extras' => [
                                 ['type' => 'social', 'label' => 'Telegram', 'value' => '@dreammore'],
                                 ['type' => 'email', 'label' => '', 'value' => 'jobs@dreammore.et'],
                             ],
                         ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data['general']['contact_extras']);
        $this->assertEquals('social', $data['general']['contact_extras'][0]['type']);
        $this->assertEquals('Telegram', $data['general']['contact_extras'][0]['label']);

        // Stored with a json type so the decoded array survives a re-read
        $this->assertDatabaseHas('admin_settings', [
            'key'  => 'contact_extras',
            'type' => 'json',
        ]);
    }

    public function test_update_rejects_invalid_extra_contact_type(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'contact_extras' => [
                     ['type' => 'fax', 'label' => '', 'value' => '12345'],
                 ],
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['contact_extras.0.type']);
    }

    // ─── SOCIAL MEDIA LINKS ────────────────────────────────────────────

    public function test_admin_can_store_social_links(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'social_links' => [
                                 ['platform' => 'github', 'url' => 'github.com/dreammore'],
                                 ['platform' => 'telegram', 'url' => 't.me/dreammore'],
                             ],
                         ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data['general']['social_links']);
        $this->assertEquals('github', $data['general']['social_links'][0]['platform']);
        $this->assertEquals('github.com/dreammore', $data['general']['social_links'][0]['url']);

        // Stored with a json type so the decoded array survives a re-read
        $this->assertDatabaseHas('admin_settings', [
            'key'  => 'social_links',
            'type' => 'json',
        ]);
    }

    public function test_update_rejects_invalid_social_platform(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'social_links' => [
                     ['platform' => 'myspace', 'url' => 'myspace.com/dreammore'],
                 ],
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['social_links.0.platform']);
    }

    public function test_admin_can_store_social_link_with_empty_url(): void
    {
        // Empty URLs are allowed — the footer hides links without a URL.
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'social_links' => [
                     ['platform' => 'github', 'url' => ''],
                 ],
             ])
             ->assertStatus(200);

        $data = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                      ->getJson('/api/v1/admin/settings')
                      ->json('data');
        $this->assertCount(1, $data['general']['social_links']);
        $this->assertEquals('', $data['general']['social_links'][0]['url']);
    }

    // ─── VALIDATION ────────────────────────────────────────────────────

    public function test_update_rejects_invalid_email(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'contact_email' => 'not-an-email',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['contact_email']);
    }

    public function test_update_rejects_invalid_registration_open_value(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'registration_open' => 'maybe',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['registration_open']);
    }

    public function test_update_rejects_invalid_maintenance_mode_value(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'maintenance_mode' => 'maybe',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['maintenance_mode']);
    }

    public function test_update_rejects_platform_name_too_long(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'platform_name' => str_repeat('A', 256),
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['platform_name']);
    }

    public function test_update_rejects_platform_description_too_long(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'platform_description' => str_repeat('A', 2001),
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['platform_description']);
    }

    public function test_update_allows_null_values(): void
    {
        // Setting a value first, then sending null should not overwrite it
        AdminSetting::setValue('platform_name', 'Keep Me', 'string');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', [
                             'platform_name' => null,
                             'contact_email' => 'only-this@test.com',
                         ]);

        $response->assertStatus(200);

        // platform_name should remain unchanged
        $this->assertDatabaseHas('admin_settings', [
            'key'   => 'platform_name',
            'value' => 'Keep Me',
        ]);
        // contact_email should be updated
        $this->assertDatabaseHas('admin_settings', [
            'key'   => 'contact_email',
            'value' => 'only-this@test.com',
        ]);
    }

    public function test_empty_payload_returns_current_settings(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->putJson('/api/v1/admin/settings', []);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'general'    => ['platform_name'],
                         'payments'   => ['default_currency'],
                         'access'     => ['registration_open'],
                     ],
                 ]);
    }

    // ─── BOOLEAN PARSING REGRESSION ──────────────────────────────────

    public function test_false_string_is_not_parsed_as_true(): void
    {
        // This was the original bug: the string "false" was cast to (bool) "false" = true.
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'maintenance_mode' => 'false',
             ])
             ->assertStatus(200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.access.maintenance_mode'));
    }

    public function test_true_string_is_parsed_correctly(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'registration_open' => 'true',
             ])
             ->assertStatus(200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.access.registration_open'));
    }

    public function test_toggling_boolean_back_and_forth(): void
    {
        // Toggle maintenance_mode to false
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', ['maintenance_mode' => 'false'])
             ->assertStatus(200);

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                      ->getJson('/api/v1/admin/settings');
        $this->assertFalse($res1->json('data.access.maintenance_mode'));

        // Toggle back to true
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', ['maintenance_mode' => 'true'])
             ->assertStatus(200);

        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                      ->getJson('/api/v1/admin/settings');
        $this->assertTrue($res2->json('data.access.maintenance_mode'));

        // Toggle back to false again
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', ['maintenance_mode' => 'false'])
             ->assertStatus(200);

        $res3 = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                      ->getJson('/api/v1/admin/settings');
        $this->assertFalse($res3->json('data.access.maintenance_mode'));
    }

    // ─── PERSISTENCE / INTEGRITY ──────────────────────────────────────

    public function test_settings_persist_across_requests(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'platform_name' => 'Persisted Value',
             ])
             ->assertStatus(200);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
                         ->getJson('/api/v1/admin/settings');

        $response->assertStatus(200)
                 ->assertJsonPath('data.general.platform_name', 'Persisted Value');
    }

    public function test_update_uses_transaction_rollback_on_failure(): void
    {
        // Verify default is set
        AdminSetting::setValue('platform_name', 'Before', 'string');

        // Send invalid data that should fail validation (not a DB error, but validates the flow)
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
             ->putJson('/api/v1/admin/settings', [
                 'contact_email' => 'invalid-email',
             ])
             ->assertStatus(422);

        // Original value should be untouched
        $this->assertDatabaseHas('admin_settings', [
            'key'   => 'platform_name',
            'value' => 'Before',
        ]);
    }
}
