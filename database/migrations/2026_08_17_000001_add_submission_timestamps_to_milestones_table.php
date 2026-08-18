<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add submission tracking timestamps to milestones (Stage 12).
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('due_date');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'approved_at']);
        });
    }
};
