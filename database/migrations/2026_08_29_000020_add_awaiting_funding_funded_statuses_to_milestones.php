<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add awaiting_funding and funded statuses to the milestones enum.
 * Also add started_at timestamp to track when freelancers begin work.
 *
 * Flow: awaiting_funding → funded → in_progress → submitted → approved → released
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add started_at column only if it doesn't exist
        if (!Schema::hasColumn('milestones', 'started_at')) {
            Schema::table('milestones', function (Blueprint $table) {
                $table->timestamp('started_at')
                    ->nullable()
                    ->after('escrow_funded_at');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            // Step 1: Convert ENUM to VARCHAR temporarily (allows any value)
            DB::statement("ALTER TABLE milestones MODIFY status VARCHAR(30) NOT NULL DEFAULT 'awaiting_funding'");

            // Step 2: Update existing data
            DB::table('milestones')
                ->where('status', 'pending')
                ->update(['status' => 'awaiting_funding']);

            DB::table('milestones')
                ->where('status', 'awaiting_funding')
                ->whereNotNull('escrow_funded_at')
                ->update(['status' => 'funded']);

            // Step 3: Now set the new ENUM (all existing values are valid)
            DB::statement(
                "ALTER TABLE milestones MODIFY status ENUM('awaiting_funding','funded','in_progress','submitted','revision_requested','approved','released','paid','disputed','cancelled') NOT NULL DEFAULT 'awaiting_funding'"
            );
        }
    }

    public function down(): void
    {
        DB::table('milestones')
            ->whereIn('status', ['awaiting_funding', 'funded'])
            ->update(['status' => 'pending']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");
            DB::statement(
                "ALTER TABLE milestones MODIFY status ENUM('pending','in_progress','submitted','revision_requested','approved','paid') NOT NULL DEFAULT 'pending'"
            );
        }

        if (Schema::hasColumn('milestones', 'started_at')) {
            Schema::table('milestones', function (Blueprint $table) {
                $table->dropColumn('started_at');
            });
        }
    }
};
