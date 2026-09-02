<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'nickname')) {
                $table->string('nickname')->nullable()->after('type');
            }
            if (!Schema::hasColumn('payment_methods', 'provider_token')) {
                $table->string('provider_token')->nullable()->after('provider');
            }
            if (!Schema::hasColumn('payment_methods', 'masked_identifier')) {
                $table->string('masked_identifier')->nullable()->after('provider_token');
            }
            if (!Schema::hasColumn('payment_methods', 'display_label')) {
                $table->string('display_label')->nullable()->after('label');
            }
            if (!Schema::hasColumn('payment_methods', 'card_brand')) {
                $table->string('card_brand')->nullable()->after('display_label');
            }
            if (!Schema::hasColumn('payment_methods', 'card_last_four')) {
                $table->string('card_last_four', 4)->nullable()->after('card_brand');
            }
            if (!Schema::hasColumn('payment_methods', 'card_exp_month')) {
                $table->string('card_exp_month', 2)->nullable()->after('card_last_four');
            }
            if (!Schema::hasColumn('payment_methods', 'card_exp_year')) {
                $table->string('card_exp_year', 4)->nullable()->after('card_exp_month');
            }
            if (!Schema::hasColumn('payment_methods', 'cardholder_name')) {
                $table->string('cardholder_name')->nullable()->after('card_exp_year');
            }
            if (!Schema::hasColumn('payment_methods', 'account_name')) {
                $table->string('account_name')->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('payment_methods', 'masked_account_number')) {
                $table->string('masked_account_number')->nullable()->after('account_name');
            }
            if (!Schema::hasColumn('payment_methods', 'mobile_provider')) {
                $table->string('mobile_provider')->nullable()->after('masked_account_number');
            }
            if (!Schema::hasColumn('payment_methods', 'masked_phone')) {
                $table->string('masked_phone')->nullable()->after('mobile_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn([
                'nickname',
                'provider_token',
                'masked_identifier',
                'display_label',
                'card_brand',
                'card_last_four',
                'card_exp_month',
                'card_exp_year',
                'cardholder_name',
                'account_name',
                'masked_account_number',
                'mobile_provider',
                'masked_phone',
            ]);
        });
    }
};
