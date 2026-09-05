<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow finance history entries (transactions, withdrawals, payments) to be
 * soft-deleted so users/admins can remove entries from history lists without
 * destroying the underlying financial records or corrupting wallet balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'withdrawals', 'payments'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->timestamp('deleted_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['transactions', 'withdrawals', 'payments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('deleted_at');
                });
            }
        }
    }
};
