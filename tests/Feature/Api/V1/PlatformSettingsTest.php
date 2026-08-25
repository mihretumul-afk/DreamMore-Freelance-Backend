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
                         'support_email',
                         'default_currency',
                         'min_proposal_amount',
                         'max_proposal_amount',
                         'registration_open',
                         'maintenance_mode',
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
}
