<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'contract_id')) {
                    $table->foreignId('contract_id')->nullable()->after('payee_id')->constrained()->nullOnDelete();
                }
                if (!Schema::hasColumn('payments', 'platform_fee')) {
                    $table->decimal('platform_fee', 12, 2)->default(0)->after('amount');
                }
                if (!Schema::hasColumn('payments', 'processing_fee')) {
                    $table->decimal('processing_fee', 12, 2)->default(0)->after('platform_fee');
                }
                if (!Schema::hasColumn('payments', 'fee')) {
                    $table->decimal('fee', 12, 2)->default(0)->after('processing_fee');
                }
                if (!Schema::hasColumn('payments', 'net_amount')) {
                    $table->decimal('net_amount', 12, 2)->default(0)->after('fee');
                }
                if (!Schema::hasColumn('payments', 'description')) {
                    $table->text('description')->nullable()->after('provider_reference');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (Schema::hasColumn('payments', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::hasColumn('payments', 'net_amount')) {
                    $table->dropColumn('net_amount');
                }
                if (Schema::hasColumn('payments', 'fee')) {
                    $table->dropColumn('fee');
                }
                if (Schema::hasColumn('payments', 'processing_fee')) {
                    $table->dropColumn('processing_fee');
                }
                if (Schema::hasColumn('payments', 'platform_fee')) {
                    $table->dropColumn('platform_fee');
                }
                if (Schema::hasColumn('payments', 'contract_id')) {
                    $table->dropForeign(['contract_id']);
                    $table->dropColumn('contract_id');
                }
            });
        }
    }
};
