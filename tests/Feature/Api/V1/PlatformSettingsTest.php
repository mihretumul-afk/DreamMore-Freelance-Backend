<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_fetch_platform_settings(): void
    {
        $response = $this->getJson('/api/v1/platform-settings');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Platform settings retrieved successfully.',
                 ])
                 ->assertJsonStructure([
                     'data' => [
                         'platform_name',
                         'platform_description',
                         'contact_email',
                         'contact_phone',
                         'contact_location',
                         'contact_hours',
                         'default_currency',
                         'registration_open',
                         'maintenance_mode',
                         'platform_fee_percent',
                         'auto_approve_days',
                         'featured_jobs_enabled',
                         'featured_profiles_enabled',
                     ],
                 ]);
    }

    public function test_settings_return_defaults_when_empty(): void
    {
        $response = $this->getJson('/api/v1/platform-settings');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Dream More AppWorks', $data['platform_name']);
        $this->assertEquals('ETB', $data['default_currency']);
        $this->assertTrue($data['registration_open']);
        $this->assertFalse($data['maintenance_mode']);
    }

    public function test_settings_return_stored_values(): void
    {
        AdminSetting::setValue('platform_name', 'Custom Platform', 'string');
        AdminSetting::setValue('maintenance_mode', 'true', 'boolean');
        AdminSetting::setValue('default_currency', 'USD', 'string');

        $response = $this->getJson('/api/v1/platform-settings');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Custom Platform', $data['platform_name']);
        $this->assertTrue($data['maintenance_mode']);
        $this->assertEquals('USD', $data['default_currency']);
    }

    public function test_no_auth_required(): void
    {
        // Ensure the endpoint works without any authentication
        $response = $this->getJson('/api/v1/platform-settings');
        $response->assertStatus(200);
    }

    public function test_contact_extras_default_to_empty(): void
    {
        $data = $this->getJson('/api/v1/platform-settings')->json('data');
        $this->assertEquals([], $data['contact_extras']);
    }

    public function test_public_settings_expose_stored_extra_contacts(): void
    {
        AdminSetting::setValue('contact_extras', [
            ['type' => 'website', 'label' => 'Portfolio', 'value' => 'dreammore.et'],
        ], 'json');

        $data = $this->getJson('/api/v1/platform-settings')->json('data');
        $this->assertEquals('Portfolio', $data['contact_extras'][0]['label']);
        $this->assertEquals('dreammore.et', $data['contact_extras'][0]['value']);
    }

    public function test_social_links_default_to_empty(): void
    {
        $data = $this->getJson('/api/v1/platform-settings')->json('data');
        $this->assertEquals([], $data['social_links']);
    }

    public function test_public_settings_expose_stored_social_links(): void
    {
        AdminSetting::setValue('social_links', [
            ['platform' => 'github', 'url' => 'github.com/dreammore'],
            ['platform' => 'telegram', 'url' => 't.me/dreammore'],
        ], 'json');

        $data = $this->getJson('/api/v1/platform-settings')->json('data');
        $this->assertCount(2, $data['social_links']);
        $this->assertEquals('github', $data['social_links'][0]['platform']);
        $this->assertEquals('github.com/dreammore', $data['social_links'][0]['url']);
    }
}
