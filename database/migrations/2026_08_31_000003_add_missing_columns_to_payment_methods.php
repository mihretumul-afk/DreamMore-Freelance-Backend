<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'label')) {
                $table->string('label')->after('provider');
            }
            if (!Schema::hasColumn('payment_methods', 'last_four')) {
                $table->string('last_four', 4)->nullable()->after('label');
            }
            if (!Schema::hasColumn('payment_methods', 'brand')) {
                $table->string('brand')->nullable()->after('last_four');
            }
            if (!Schema::hasColumn('payment_methods', 'expiry_month')) {
                $table->string('expiry_month', 2)->nullable()->after('brand');
            }
            if (!Schema::hasColumn('payment_methods', 'expiry_year')) {
                $table->string('expiry_year', 4)->nullable()->after('expiry_month');
            }
            if (!Schema::hasColumn('payment_methods', 'account_number_last_four')) {
                $table->string('account_number_last_four', 4)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('payment_methods', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('is_default');
            }
            if (!Schema::hasColumn('payment_methods', 'metadata')) {
                $table->json('metadata')->nullable()->after('is_verified');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn([
                'label', 'last_four', 'brand', 'expiry_month', 'expiry_year',
                'account_number_last_four', 'is_verified', 'metadata',
            ]);
        });
    }
};
