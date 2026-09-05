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
            'contact_email'        => AdminSetting::getValue('contact_email', 'support@appworks.et', 'string'),
            'contact_phone'        => AdminSetting::getValue('contact_phone', '+251 911 234 567', 'string'),
            'contact_location'     => AdminSetting::getValue('contact_location', 'Addis Ababa, Ethiopia', 'string'),
            'contact_hours'        => AdminSetting::getValue('contact_hours', 'Mon–Fri, 9:00 AM – 6:00 PM (EAT)', 'string'),
            'contact_extras'       => self::extraContacts(),
            'social_links'         => self::socialLinks(),
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

    /**
     * Additional contacts added by admins (Admin → Settings → General).
     * Stored as a JSON-typed AdminSetting value; decoded to an array here.
     */
    private static function extraContacts(): array
    {
        $extras = AdminSetting::getValue('contact_extras', '[]', 'json');
        return is_array($extras) ? array_values($extras) : [];
    }

    /**
     * Social media links shown in the footer (Admin → Settings → General).
     * Stored as a JSON-typed AdminSetting value; decoded to an array here.
     */
    private static function socialLinks(): array
    {
        $links = AdminSetting::getValue('social_links', [], 'json');
        return is_array($links) ? array_values($links) : [];
    }
}
