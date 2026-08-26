<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments — one record per payment event (escrow funding, milestone release,
 * refund, platform fee).
 *
 * This is the canonical financial ledger. Every money movement creates a row
 * here. A corresponding transaction record is also created for the user-facing
 * timeline (see transactions table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Unique human-readable reference: PAY-2026-000001
            $table->string('reference', 50)->unique()->index();

            // Payer (employer for escrow/funding; freelancer for refund cases)
            $table->foreignId('payer_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Payee (freelancer for milestone release; null for platform fee)
            $table->foreignId('payee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Context — what this payment is for
            $table->foreignId('contract_id')
                ->nullable()
                ->constrained('contracts')
                ->nullOnDelete();

            $table->foreignId('milestone_id')
                ->nullable()
                ->constrained('milestones')
                ->nullOnDelete();

            // The payment method used (nullable until gateway integration)
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();

            // Type of payment event
            // escrow_funded      — employer funded the contract/milestone into escrow
            // milestone_released — escrow released to freelancer on approval
            // refund             — funds returned to employer
            // platform_fee       — platform commission deducted
            // manual_adjustment  — admin-applied correction
            $table->string('type', 30)->index();

            // Financial amounts (all in platform currency ETB unless noted)
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0.00);       // platform fee
            $table->decimal('net_amount', 14, 2);               // amount - fee
            $table->string('currency', 10)->default('ETB');

            // Status lifecycle
            // pending → processing → completed
            //         → failed
            //         → refunded (from completed)
            //         → cancelled
            //         → disputed
            $table->string('status', 20)->default('pending')->index();

            // Provider details (populated when real gateway is connected)
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 255)->nullable(); // gateway txn ID
            $table->json('provider_response')->nullable();          // raw gateway payload

            // Human-readable description shown in UI
            $table->string('description', 500)->nullable();

            // Admin notes (internal, never shown to users)
            $table->text('admin_notes')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['payer_id', 'status']);
            $table->index(['payee_id', 'status']);
            $table->index(['contract_id', 'type']);
            $table->index(['milestone_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
