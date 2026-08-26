<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add payment tracking fields to the milestones table.
 *
 * paid_at      — timestamp when the milestone was paid out to the freelancer
 * payment_id   — FK to the payments record that released this milestone
 * escrow_funded_at — timestamp when the employer funded the escrow for this milestone
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->timestamp('paid_at')
                ->nullable()
                ->after('approved_at');

            $table->foreignId('payment_id')
                ->nullable()
                ->after('paid_at')
                ->constrained('payments')
                ->nullOnDelete();

            $table->timestamp('escrow_funded_at')
                ->nullable()
                ->after('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn(['paid_at', 'payment_id', 'escrow_funded_at']);
        });
    }
};
