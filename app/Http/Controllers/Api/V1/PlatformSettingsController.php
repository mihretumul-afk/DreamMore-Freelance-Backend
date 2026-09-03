<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AdminSetting;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingsController extends BaseApiController
{
    /**
     * Public / admin view of platform settings.
     */
    public function index(): JsonResponse
    {
        $platformFee = PlatformSetting::get('platform_fee_percent', '8');
        $autoApprove = PlatformSetting::get('auto_approve_days', '5');

        $settings = [
            'platform_name'        => AdminSetting::getValue('platform_name', 'Dream More AppWorks', 'string'),
            'platform_description' => AdminSetting::getValue('platform_description', 'The Ethiopian freelance marketplace', 'string'),
            'support_email'        => AdminSetting::getValue('support_email', 'support@dreammore.com', 'string'),
            'default_currency'     => AdminSetting::getValue('default_currency', 'ETB', 'string'),
            'registration_open'    => AdminSetting::getValue('registration_open', 'true', 'boolean'),
            'maintenance_mode'     => AdminSetting::getValue('maintenance_mode', 'false', 'boolean'),
            'platform_fee_percent' => (float) $platformFee,
            'auto_approve_days'    => (int) $autoApprove,

            // Featured Listing settings (public for frontend feature detection)
            'featured_jobs_enabled'     => AdminSetting::getValue('featured_jobs_enabled', 'false', 'boolean'),
            'featured_profiles_enabled' => AdminSetting::getValue('featured_profiles_enabled', 'false', 'boolean'),
        ];

        return $this->sendResponse($settings, 'Platform settings retrieved successfully.');
    }

    /**
     * Admin updates platform fee percentage (range 0 to 50).
     */
    public function updateFee(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->sendForbidden('Only administrators can update platform fee settings.');
        }

        $validated = $request->validate([
            'platform_fee_percent' => 'required|numeric|min:0|max:50',
            'auto_approve_days' => 'nullable|integer|min:1|max:30',
        ]);

        PlatformSetting::set('platform_fee_percent', $validated['platform_fee_percent']);

        if (isset($validated['auto_approve_days'])) {
            PlatformSetting::set('auto_approve_days', $validated['auto_approve_days']);
        }

        return $this->sendResponse([
            'platform_fee_percent' => (float) PlatformSetting::get('platform_fee_percent', '8'),
            'auto_approve_days' => (int) PlatformSetting::get('auto_approve_days', '5'),
        ], 'Platform fee updated successfully.');
    }
}
