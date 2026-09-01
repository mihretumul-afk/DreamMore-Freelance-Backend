<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('wallet_id')->nullable()->after('user_id');
            $table->decimal('balance_before', 12, 2)->default(0)->after('amount');
            $table->decimal('balance_after', 12, 2)->default(0)->after('balance_before');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['wallet_id', 'balance_before', 'balance_after']);
        });
    }
};
