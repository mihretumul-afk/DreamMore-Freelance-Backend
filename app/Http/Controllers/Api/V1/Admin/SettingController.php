<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\UpdateAdminSettingsRequest;
use App\Models\AdminSetting;
use App\Models\PlatformSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingController extends BaseApiController
{
    private const DEFAULT_SETTINGS = [
        'platform_name'        => ['value' => 'Dream More AppWorks', 'type' => 'string'],
        'platform_description' => ['value' => 'The Ethiopian freelance marketplace', 'type' => 'string'],
        'default_currency'     => ['value' => 'ETB', 'type' => 'string'],

        // Contract / Milestone fee setting
        'platform_fee_percent' => ['value' => '8', 'type' => 'string'],

        // Milestone auto-release timeframe (days unreviewed before escrow released to freelancer)
        'auto_release_days'    => ['value' => '14', 'type' => 'integer'],

        // Contact page (footer Contact button → /contact)
        'contact_email'    => ['value' => 'support@appworks.et', 'type' => 'string'],
        'contact_phone'    => ['value' => '+251 911 234 567', 'type' => 'string'],
        'contact_location' => ['value' => 'Addis Ababa, Ethiopia', 'type' => 'string'],
        'contact_hours'    => ['value' => 'Mon–Fri, 9:00 AM – 6:00 PM (EAT)', 'type' => 'string'],
        // Extra contact entries added by admins: [{ type, label, value }]
        'contact_extras'   => ['value' => '[]', 'type' => 'json'],
        // Social media links shown in the footer: [{ platform, url }]
        'social_links'     => ['value' => '[{"platform":"github","url":""},{"platform":"telegram","url":""},{"platform":"facebook","url":""},{"platform":"instagram","url":""}]', 'type' => 'json'],
        'registration_open'    => ['value' => 'true', 'type' => 'boolean'],
        'maintenance_mode'     => ['value' => 'false', 'type' => 'boolean'],

        // Featured Listing settings
        'featured_jobs_enabled'             => ['value' => 'false', 'type' => 'boolean'],
        'featured_profiles_enabled'         => ['value' => 'false', 'type' => 'boolean'],
        'featured_job_price'                => ['value' => '150', 'type' => 'string'],
        'featured_job_duration_days'        => ['value' => '7', 'type' => 'integer'],
        'featured_profile_price'            => ['value' => '100', 'type' => 'string'],
        'featured_profile_duration_days'    => ['value' => '7', 'type' => 'integer'],
    ];

    /**
     * GET /api/v1/admin/settings
     */
    public function index(): JsonResponse
    {
        $raw = [];
        foreach (self::DEFAULT_SETTINGS as $key => $defaults) {
            $raw[$key] = AdminSetting::getValue($key, $defaults['value'], $defaults['type']);
        }

        $grouped = [
            'general'  => [
                'platform_name'        => $raw['platform_name'],
                'platform_description' => $raw['platform_description'],
                'contact_email'        => $raw['contact_email'],
                'contact_phone'        => $raw['contact_phone'],
                'contact_location'     => $raw['contact_location'],
                'contact_hours'        => $raw['contact_hours'],
                'contact_extras'       => $raw['contact_extras'] ?? [],
                'social_links'         => $raw['social_links'] ?? [],
            ],
            'payments' => [
                'default_currency'     => $raw['default_currency'],
                'platform_fee_percent' => $raw['platform_fee_percent'],
                'auto_release_days'    => $raw['auto_release_days'],
            ],
            'access'   => [
                'registration_open' => $raw['registration_open'],
                'maintenance_mode'  => $raw['maintenance_mode'],
            ],
            'featured' => [
                'featured_jobs_enabled'             => $raw['featured_jobs_enabled'],
                'featured_profiles_enabled'         => $raw['featured_profiles_enabled'],
                'featured_job_price'                => $raw['featured_job_price'],
                'featured_job_duration_days'        => $raw['featured_job_duration_days'],
                'featured_profile_price'            => $raw['featured_profile_price'],
                'featured_profile_duration_days'    => $raw['featured_profile_duration_days'],
            ],
        ];

        return $this->sendResponse($grouped, 'Settings retrieved successfully.');
    }

    /**
     * PUT /api/v1/admin/settings
     */
    public function update(UpdateAdminSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor     = $request->user();

        // Capture old values for the audit context before updating.
        $oldValues = [];
        $changedKeys = [];
        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $oldValues[$key] = AdminSetting::getValue(
                    $key,
                    self::DEFAULT_SETTINGS[$key]['value'] ?? null,
                    self::DEFAULT_SETTINGS[$key]['type']  ?? 'string'
                );
                $changedKeys[$key] = $value;
            }
        }

        DB::transaction(function () use ($validated, $actor, $oldValues, $changedKeys) {
            foreach ($validated as $key => $value) {
                if ($value !== null) {
                    $type = self::DEFAULT_SETTINGS[$key]['type'] ?? 'string';
                    AdminSetting::setValue($key, $value, $type);

                    // Sync platform fee percentage with PlatformSetting store as well
                    if ($key === 'platform_fee_percent') {
                        PlatformSetting::set('platform_fee_percent', (string) $value);
                    }
                }
            }

            if (!empty($changedKeys)) {
                AuditService::settingsChanged($actor->id, [
                    'changed' => $changedKeys,
                    'old'     => $oldValues,
                ]);
            }
        });

        return $this->index();
    }
}
