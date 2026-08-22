<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add reviewed_by to verifications table if it doesn't exist
        if (!Schema::hasColumn('verifications', 'reviewed_by')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        // Alter contracts status column to allow 'disputed'
        // Using DB statement for MySQL enum expansion
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('active', 'completed', 'paused', 'cancelled', 'disputed') DEFAULT 'active'");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('verifications', 'reviewed_by')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->dropForeign(['reviewed_by']);
                $table->dropColumn('reviewed_by');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('active', 'completed', 'paused', 'cancelled') DEFAULT 'active'");
        }
    }
};
