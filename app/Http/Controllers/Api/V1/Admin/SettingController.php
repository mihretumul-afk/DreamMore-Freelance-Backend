<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends BaseApiController
{
    private const DEFAULT_SETTINGS = [
        'platform_name' => ['value' => 'Dream More AppWorks', 'type' => 'string'],
        'platform_description' => ['value' => 'The Ethiopian freelance marketplace', 'type' => 'string'],
        'support_email' => ['value' => 'support@dreammore.com', 'type' => 'string'],
        'default_currency' => ['value' => 'ETB', 'type' => 'string'],
        'min_proposal_amount' => ['value' => '', 'type' => 'string'],
        'max_proposal_amount' => ['value' => '', 'type' => 'string'],
        'registration_open' => ['value' => 'true', 'type' => 'boolean'],
        'maintenance_mode' => ['value' => 'false', 'type' => 'boolean'],
    ];

    public function index(): JsonResponse
    {
        $settings = [];
        foreach (self::DEFAULT_SETTINGS as $key => $defaults) {
            $settings[$key] = AdminSetting::getValue($key, $defaults['value']);
        }

        return $this->sendResponse($settings, 'Settings retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_name' => 'nullable|string|max:255',
            'platform_description' => 'nullable|string|max:2000',
            'support_email' => 'nullable|email|max:255',
            'default_currency' => 'nullable|string|max:10',
            'min_proposal_amount' => 'nullable|string',
            'max_proposal_amount' => 'nullable|string',
            'registration_open' => 'nullable|string|in:true,false',
            'maintenance_mode' => 'nullable|string|in:true,false',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $type = self::DEFAULT_SETTINGS[$key]['type'] ?? 'string';
                AdminSetting::setValue($key, $value, $type);
            }
        }

        // Return the full settings.
        return $this->index();
    }
}
