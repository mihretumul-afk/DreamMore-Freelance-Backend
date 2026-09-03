<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 10)->default('ETB');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bank_name');
            $table->string('bank_code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('reference');
            $table->foreign('processed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_withdrawals');
    }
};
