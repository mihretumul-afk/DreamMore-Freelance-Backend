<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add refund-request workflow columns to the payments table.
 *
 * refund_requested_at  — when the employer requested a refund
 * refund_requested_by  — which user requested it
 * refund_approved_at   — when an admin approved the refund request
 * refund_approved_by   — which admin approved it
 * refund_reason        — employer's stated reason for the refund
 * refund_status        — none | requested | approved | rejected | completed | failed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_status', 20)->default('none')->after('failure_reason')->index();
            $table->text('refund_reason')->nullable()->after('refund_status');
            $table->timestamp('refund_requested_at')->nullable()->after('refund_reason');
            $table->foreignId('refund_requested_by')->nullable()->after('refund_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('refund_approved_at')->nullable()->after('refund_requested_by');
            $table->foreignId('refund_approved_by')->nullable()->after('refund_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['refund_requested_by']);
            $table->dropForeign(['refund_approved_by']);
            $table->dropColumn([
                'refund_status', 'refund_reason',
                'refund_requested_at', 'refund_requested_by',
                'refund_approved_at', 'refund_approved_by',
            ]);
        });
    }
};
