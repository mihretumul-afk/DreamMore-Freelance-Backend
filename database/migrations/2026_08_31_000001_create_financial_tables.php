<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Payment methods
        if (!Schema::hasTable('payment_methods')) {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // card, bank, mobile_money
            $table->string('provider'); // stripe, telebirr, cbe, etc.
            $table->string('provider_payment_method_id')->nullable(); // tokenized ID from provider
            $table->string('label'); // "Visa ending 4242", "CBE Account"
            $table->string('last_four')->nullable();
            $table->string('brand')->nullable(); // visa, mastercard, etc.
            $table->string('expiry_month')->nullable();
            $table->string('expiry_year')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number_last_four')->nullable();
            $table->string('mobile_number')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });
        }

        // 2. Wallets (earnings balances)
        if (!Schema::hasTable('wallets')) {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->decimal('available_balance', 12, 2)->default(0);
            $table->decimal('pending_balance', 12, 2)->default(0);
            $table->decimal('held_balance', 12, 2)->default(0);
            $table->decimal('total_earned', 12, 2)->default(0);
            $table->decimal('total_withdrawn', 12, 2)->default(0);
            $table->string('currency', 10)->default('ETB');
            $table->timestamps();
        });
        }

        // 3. Payments
        if (!Schema::hasTable('payments')) {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // unique payment reference
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('processing_fee', 12, 2)->default(0);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2); // amount after fees
            $table->string('currency', 10)->default('ETB');
            $table->string('type'); // escrow_funded, milestone_released, refund, withdrawal
            $table->string('status'); // pending, processing, completed, failed, cancelled, refunded, disputed
            $table->string('provider')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['payer_id', 'status']);
            $table->index(['milestone_id', 'type']);
            $table->index('reference');
        });
        }

        // 4. Transactions (immutable ledger)
        if (!Schema::hasTable('transactions')) {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2); // positive = credit, negative = debit
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->string('currency', 10)->default('ETB');
            $table->string('type'); // payment, platform_fee, processing_fee, funds_held, funds_released, refund, withdrawal, withdrawal_fee, adjustment
            $table->string('direction'); // credit, debit
            $table->string('status'); // pending, completed, failed, reversed
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('type');
            $table->index('reference');
        });
        }

        // 5. Withdrawals
        if (!Schema::hasTable('withdrawals')) {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('currency', 10)->default('ETB');
            $table->string('status'); // requested, processing, completed, failed, cancelled
            $table->string('provider')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        }

        // 6. Webhook logs (for idempotency)
        if (!Schema::hasTable('webhook_logs')) {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('event_type', 100);
            $table->string('provider_reference', 100)->nullable();
            $table->json('payload');
            $table->string('status', 20); // received, processed, failed, duplicate
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['provider', 'provider_reference']);
            $table->index('status');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('payment_methods');
    }
};
