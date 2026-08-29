<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add pending_acceptance to contracts status enum
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY status ENUM('pending_acceptance','active','completed','paused','cancelled','disputed') DEFAULT 'pending_acceptance'");
        }

        // Merge all existing + new milestone statuses (preserve existing data)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('draft','awaiting_funding','unfunded','funded','in_progress','submitted','in_review','revision_requested','approved','disputed','released','paid','cancelled') DEFAULT 'draft'");
        }

        // Add deliverables and revision_note columns to milestones if not present
        Schema::table('milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('milestones', 'deliverables')) {
                $table->text('deliverables')->nullable()->after('description');
            }
            if (!Schema::hasColumn('milestones', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('due_date');
            }
            if (!Schema::hasColumn('milestones', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('milestones', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('accepted_at');
            }
        });

        // Add terms column to contracts if not present
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'terms')) {
                $table->text('terms')->nullable()->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['deliverables', 'created_by', 'accepted_at', 'started_at']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('terms');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY status ENUM('active','completed','paused','cancelled','disputed') DEFAULT 'active'");
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('draft','awaiting_funding','unfunded','funded','in_progress','submitted','in_review','revision_requested','approved','disputed','released','paid','cancelled') DEFAULT 'draft'");
        }
    }
};
