<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_success(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'AppWorks API is online and operational.',
                     'data' => [
                         'service' => 'Dream More AppWorks API',
                         'status' => 'healthy',
                         'version' => '1.0.0',
                     ],
                 ]);
    }

    public function test_status_endpoint_returns_system_info(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ])
                 ->assertJsonStructure([
                     'data' => [
                         'environment',
                         'debug',
                         'php_version',
                         'laravel_version',
                     ],
                 ]);
    }
}
