<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seeds the AdminSetting table with featured listing feature flags,
     * prices, and duration defaults. All features are OFF by default.
     */
    public function up(): void
    {
        $now = now()->toDateTimeString();

        $settings = [
            // Feature toggles (disabled by default)
            [
                'key'   => 'featured_jobs_enabled',
                'value' => 'false',
                'type'  => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'   => 'featured_profiles_enabled',
                'value' => 'false',
                'type'  => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Featured Job pricing and duration
            [
                'key'   => 'featured_job_price',
                'value' => '150',
                'type'  => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'   => 'featured_job_duration_days',
                'value' => '7',
                'type'  => 'integer',
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Featured Profile pricing and duration
            [
                'key'   => 'featured_profile_price',
                'value' => '100',
                'type'  => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key'   => 'featured_profile_duration_days',
                'value' => '7',
                'type'  => 'integer',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('admin_settings')->insert($settings);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('admin_settings')->whereIn('key', [
            'featured_jobs_enabled',
            'featured_profiles_enabled',
            'featured_job_price',
            'featured_job_duration_days',
            'featured_profile_price',
            'featured_profile_duration_days',
        ])->delete();
    }
};
