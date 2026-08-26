<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_methods — stores user-saved payment instruments.
 *
 * Full card/account numbers are NEVER stored. Only masked identifiers
 * (last 4 digits, masked IBAN, wallet phone) are kept. The `provider_token`
 * column stores an opaque reference returned by a future payment gateway —
 * not raw credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // card | bank_account | mobile_money
            $table->string('type', 30)->index();

            // Human label the user gave it ("My CBE Account")
            $table->string('nickname', 100)->nullable();

            // Provider slug for future integration: manual | stripe | chapa | flutterwave
            $table->string('provider', 30)->default('manual');

            // Opaque token from payment gateway (null until real provider connected)
            $table->string('provider_token', 255)->nullable();

            // Display-safe identifiers — never full card/account numbers
            $table->string('masked_identifier', 50)->nullable(); // "**** **** **** 4242", "***1234"
            $table->string('display_label', 100)->nullable();    // "Visa ending in 4242"

            // Card-specific (no CVV, no full PAN ever)
            $table->string('card_brand', 20)->nullable();        // Visa, Mastercard
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_exp_month', 2)->nullable();
            $table->string('card_exp_year', 4)->nullable();
            $table->string('cardholder_name', 100)->nullable();

            // Bank account-specific
            $table->string('bank_name', 100)->nullable();
            $table->string('account_name', 100)->nullable();
            $table->string('masked_account_number', 50)->nullable();

            // Mobile money-specific
            $table->string('mobile_provider', 50)->nullable(); // Telebirr, M-PESA
            $table->string('masked_phone', 20)->nullable();    // "+251 9** *** 234"

            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_verified')->default(false);

            // Metadata for verification attempts / gateway responses
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
