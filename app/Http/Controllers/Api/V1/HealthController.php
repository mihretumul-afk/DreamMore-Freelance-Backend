<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

class HealthController extends BaseApiController
{
    /**
     * API Health Endpoint.
     */
    public function health(): JsonResponse
    {
        return $this->sendResponse([
            'service' => 'Dream More AppWorks API',
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ], 'AppWorks API is online and operational.');
    }

    /**
     * API Detailed Status Endpoint.
     */
    public function status(): JsonResponse
    {
        return $this->sendResponse([
            'environment' => config('app.env'),
            'debug' => config('app.debug'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ], 'AppWorks API system configuration retrieved.');
    }
}
