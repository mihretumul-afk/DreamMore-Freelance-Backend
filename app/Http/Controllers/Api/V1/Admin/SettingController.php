<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\UpdateAdminSettingsRequest;
use App\Models\AdminSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingController extends BaseApiController
{
    private const DEFAULT_SETTINGS = [
        'platform_name'        => ['value' => 'Dream More AppWorks', 'type' => 'string'],
        'platform_description' => ['value' => 'The Ethiopian freelance marketplace', 'type' => 'string'],
        'support_email'        => ['value' => 'support@dreammore.com', 'type' => 'string'],
        'default_currency'     => ['value' => 'ETB', 'type' => 'string'],
        'min_proposal_amount'  => ['value' => '', 'type' => 'string'],
        'max_proposal_amount'  => ['value' => '', 'type' => 'string'],
        'registration_open'    => ['value' => 'true', 'type' => 'boolean'],
        'maintenance_mode'     => ['value' => 'false', 'type' => 'boolean'],
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
                'support_email'        => $raw['support_email'],
            ],
            'payments' => [
                'default_currency'    => $raw['default_currency'],
                'min_proposal_amount' => $raw['min_proposal_amount'],
                'max_proposal_amount' => $raw['max_proposal_amount'],
            ],
            'access'   => [
                'registration_open' => $raw['registration_open'],
                'maintenance_mode'  => $raw['maintenance_mode'],
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
