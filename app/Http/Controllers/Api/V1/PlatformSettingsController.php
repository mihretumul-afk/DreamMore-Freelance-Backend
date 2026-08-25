<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;

class PlatformSettingsController extends BaseApiController
{
    /**
     * GET /api/v1/platform-settings
     *
     * Public endpoint — returns platform configuration that the frontend
     * needs to render the UI correctly (name, description, currency,
     * registration status, maintenance mode). No authentication required.
     */
    public function index(): JsonResponse
    {
        $settings = [
            'platform_name'        => AdminSetting::getValue('platform_name', 'Dream More AppWorks', 'string'),
            'platform_description' => AdminSetting::getValue('platform_description', 'The Ethiopian freelance marketplace', 'string'),
            'support_email'        => AdminSetting::getValue('support_email', 'support@dreammore.com', 'string'),
            'default_currency'     => AdminSetting::getValue('default_currency', 'ETB', 'string'),
            'min_proposal_amount'  => AdminSetting::getValue('min_proposal_amount', '', 'string'),
            'max_proposal_amount'  => AdminSetting::getValue('max_proposal_amount', '', 'string'),
            'registration_open'    => AdminSetting::getValue('registration_open', 'true', 'boolean'),
            'maintenance_mode'     => AdminSetting::getValue('maintenance_mode', 'false', 'boolean'),
        ];

        return $this->sendResponse($settings, 'Platform settings retrieved successfully.');
    }
}
