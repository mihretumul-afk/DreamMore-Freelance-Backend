<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Chapa bank code (e.g., 855 for Telebirr, 128 for CBEBirr, 266 for M-Pesa)
            // Used when calling Chapa's transfer/payout API
            $table->integer('bank_code')->nullable()->after('mobile_number');

            // Full account number, encrypted at rest using Laravel's 'encrypted' cast
            // Used ONLY internally by ChapaProvider::payout() — never exposed to frontend
            // masked_account_number continues to be used for UI display
            $table->text('account_number_encrypted')->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'account_number_encrypted']);
        });
    }
};
