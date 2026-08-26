<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            // Unique human-readable reference: WTH-2026-000001
            $table->string('reference', 50)->unique()->index();

            // The freelancer requesting withdrawal
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Payment method selected for withdrawal
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();

            // Financial details
            $table->decimal('amount', 14, 2);        // Requested amount
            $table->decimal('fee', 14, 2)->default(0.00);  // Withdrawal fee
            $table->decimal('net_amount', 14, 2);     // Amount after fee
            $table->string('currency', 10)->default('ETB');

            // Status lifecycle: requested → processing → completed | failed | cancelled
            $table->string('status', 20)->default('requested')->index();

            // Provider details (populated when real gateway processes withdrawal)
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 255)->nullable();
            $table->json('provider_response')->nullable();

            // Error tracking
            $table->string('failure_reason', 500)->nullable();

            // Admin tracking
            $table->text('admin_notes')->nullable();
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Timestamps
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
