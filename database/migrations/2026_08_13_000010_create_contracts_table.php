<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->enum('budget_type', ['fixed', 'hourly'])->default('fixed');
            $table->decimal('agreed_rate', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['active', 'completed', 'paused', 'cancelled'])->default('active');
            $table->timestamp('start_date')->useCurrent();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();

            $table->index('job_id');
            $table->index('proposal_id');
            $table->index('employer_id');
            $table->index('freelancer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
