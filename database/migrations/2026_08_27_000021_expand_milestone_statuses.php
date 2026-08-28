<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('milestones')
                ->where('status', 'pending')
                ->update(['status' => 'awaiting_funding']);

            Schema::table('milestones', function (Blueprint $table) {
                $table->string('status', 30)->default('awaiting_funding')->change();
            });

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE milestones MODIFY status VARCHAR(30) NOT NULL DEFAULT 'awaiting_funding'");
        DB::table('milestones')
            ->where('status', 'pending')
            ->update(['status' => 'awaiting_funding']);
        DB::statement(
            "ALTER TABLE milestones MODIFY status ENUM('awaiting_funding','funded','in_progress','submitted','revision_requested','approved','released','paid','disputed','cancelled') NOT NULL DEFAULT 'awaiting_funding'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('milestones')
                ->whereIn('status', ['awaiting_funding', 'funded'])
                ->update(['status' => 'pending']);

            Schema::table('milestones', function (Blueprint $table) {
                $table->string('status', 30)->default('pending')->change();
            });

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('milestones')
            ->whereIn('status', ['awaiting_funding', 'funded'])
            ->update(['status' => 'pending']);
        DB::statement("ALTER TABLE milestones MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");
        DB::statement(
            "ALTER TABLE milestones MODIFY status ENUM('pending','in_progress','submitted','revision_requested','approved','paid') NOT NULL DEFAULT 'pending'"
        );
    }
};