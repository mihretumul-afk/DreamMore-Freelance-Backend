<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('draft','awaiting_funding','unfunded','funded','in_progress','submitted','in_review','revision_requested','approved','disputed','released','paid','cancelled') DEFAULT 'draft'");
        }

        Schema::table('milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('milestones', 'funded_at')) {
                $table->timestamp('funded_at')->nullable()->after('started_at');
            }
            if (!Schema::hasColumn('milestones', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('funded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['funded_at', 'released_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('draft','awaiting_funding','unfunded','funded','in_progress','submitted','in_review','revision_requested','approved','disputed','released','paid','cancelled') DEFAULT 'draft'");
        }
    }
};
