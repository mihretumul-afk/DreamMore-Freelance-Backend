<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transactions — user-facing double-entry ledger.
 *
 * Each payment creates two transaction rows:
 *   - A debit entry for the payer  (amount negative from their perspective)
 *   - A credit entry for the payee (amount positive from their perspective)
 *
 * This allows each user's transaction history to show their own view of
 * money flowing in and out, with running balance support in Stage 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Human-readable reference: TXN-2026-000001
            $table->string('reference', 50)->unique()->index();

            // The payment event that generated this transaction row
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->cascadeOnDelete();

            // The user this transaction row belongs to
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // credit | debit
            $table->string('direction', 10)->index();

            // Type mirrors payment type for filtering
            $table->string('type', 30)->index();

            // Amount from this user's perspective (always positive)
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0.00);
            $table->string('currency', 10)->default('ETB');

            // Mirror status from payment
            $table->string('status', 20)->default('pending')->index();

            // Human-readable description shown in the user's transaction list
            $table->string('description', 500)->nullable();

            // Optional context links
            $table->foreignId('contract_id')
                ->nullable()
                ->constrained('contracts')
                ->nullOnDelete();

            $table->foreignId('milestone_id')
                ->nullable()
                ->constrained('milestones')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['payment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
