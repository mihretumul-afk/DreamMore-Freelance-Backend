<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. wallets table
        if (!Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->decimal('available_balance', 14, 2)->default(0.00);
                $table->decimal('pending_balance', 14, 2)->default(0.00);
                $table->string('currency', 10)->default('ETB');
                $table->timestamps();

                $table->index('user_id');
            });
        }

        // 2. contracts table (create or adjust existing)
        if (!Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
                $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
                $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('freelancer_id')->constrained('users')->cascadeOnDelete();
                $table->string('contract_type', 20)->default('fixed'); // fixed / hourly
                $table->decimal('total_amount', 14, 2)->default(0.00);
                $table->string('currency', 10)->default('ETB');
                $table->string('status', 20)->default('pending'); // pending, active, completed, cancelled, disputed
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->timestamps();

                $table->index(['employer_id', 'status']);
                $table->index(['freelancer_id', 'status']);
            });
        } else {
            Schema::table('contracts', function (Blueprint $table) {
                if (!Schema::hasColumn('contracts', 'contract_type')) {
                    $table->string('contract_type', 20)->default('fixed')->after('freelancer_id');
                }
                if (!Schema::hasColumn('contracts', 'currency')) {
                    $table->string('currency', 10)->default('ETB')->after('total_amount');
                }
            });
        }

        // 3. milestones table (create or adjust existing)
        if (!Schema::hasTable('milestones')) {
            Schema::create('milestones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('amount', 14, 2)->default(0.00);
                $table->string('status', 30)->default('pending'); // pending, funded, submitted, approved, rejected, released, disputed
                $table->timestamp('due_date')->nullable();
                $table->timestamp('funded_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['contract_id', 'status']);
            });
        } else {
            Schema::table('milestones', function (Blueprint $table) {
                if (!Schema::hasColumn('milestones', 'funded_at')) {
                    $table->timestamp('funded_at')->nullable()->after('due_date');
                }
                if (!Schema::hasColumn('milestones', 'submitted_at')) {
                    $table->timestamp('submitted_at')->nullable()->after('funded_at');
                }
                if (!Schema::hasColumn('milestones', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('submitted_at');
                }
                if (!Schema::hasColumn('milestones', 'released_at')) {
                    $table->timestamp('released_at')->nullable()->after('approved_at');
                }
                if (!Schema::hasColumn('milestones', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->after('released_at');
                }
            });
        }

        // 4. escrow_transactions table (append-only audit log)
        if (!Schema::hasTable('escrow_transactions')) {
            Schema::create('escrow_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $table->foreignId('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
                $table->string('type', 30); // funding, escrow_hold, release, platform_fee, withdrawal, refund
                $table->decimal('amount', 14, 2);
                $table->string('currency', 10)->default('ETB');
                $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20)->default('completed'); // pending, completed, failed, reversed
                $table->string('payment_method', 50)->default('test_mode');
                $table->string('gateway_reference', 100)->nullable();
                $table->timestamps();

                $table->index('contract_id');
                $table->index('milestone_id');
                $table->index('from_user_id');
                $table->index('to_user_id');
                $table->index('type');
            });
        }

        // 5. disputes table
        if (!Schema::hasTable('disputes')) {
            Schema::create('disputes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
                $table->foreignId('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
                $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
                $table->text('reason');
                $table->string('status', 30)->default('open'); // open, under_review, resolved_release, resolved_refund, resolved_split
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('resolution_note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index('contract_id');
                $table->index('milestone_id');
                $table->index('raised_by');
                $table->index('status');
            });
        }

        // 6. platform_settings table
        if (!Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });

            // Seed initial settings
            DB::table('platform_settings')->insert([
                ['key' => 'platform_fee_percent', 'value' => '8', 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'auto_approve_days', 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('escrow_transactions');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('wallets');
    }
};
