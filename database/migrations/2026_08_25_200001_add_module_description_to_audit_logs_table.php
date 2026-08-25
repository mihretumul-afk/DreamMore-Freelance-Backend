<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add module and description columns to audit_logs.
     *
     * module      — logical domain: users | roles | permissions | disputes |
     *               verifications | settings | admins | payments | milestones
     * description — pre-rendered human-readable sentence stored at log time
     *               e.g. "Support Admin Sarah suspended user john@example.com"
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Logical module the action belongs to (for frontend filter).
            $table->string('module', 50)->nullable()->index()->after('action');

            // Human-readable description stored at write time.
            $table->string('description', 500)->nullable()->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['module', 'description']);
        });
    }
};
