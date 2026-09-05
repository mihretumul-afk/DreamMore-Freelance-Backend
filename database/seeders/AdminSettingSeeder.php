<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use Illuminate\Database\Seeder;

class AdminSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'platform_name'        => ['value' => 'Dream More AppWorks', 'type' => 'string'],
            'platform_description' => ['value' => 'The Ethiopian freelance marketplace', 'type' => 'string'],
            'default_currency'     => ['value' => 'ETB', 'type' => 'string'],
            'min_proposal_amount'  => ['value' => '', 'type' => 'string'],
            'max_proposal_amount'  => ['value' => '', 'type' => 'string'],
            'registration_open'    => ['value' => 'true', 'type' => 'boolean'],
            'maintenance_mode'     => ['value' => 'false', 'type' => 'boolean'],
        ];

        foreach ($defaults as $key => $config) {
            AdminSetting::setValue($key, $config['value'], $config['type']);
        }
    }
}
