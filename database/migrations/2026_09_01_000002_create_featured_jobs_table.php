<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the featured_jobs table to track paid job visibility boosts.
     * Employers pay to have their jobs appear higher in search results.
     */
    public function up(): void
    {
        Schema::create('featured_jobs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount_paid', 12, 2);
            $table->integer('duration_days');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active'); // active, expired
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['job_id', 'status']);
            $table->index(['employer_id', 'status']);
            $table->index(['status', 'expires_at']); // For expiry cron job
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('featured_jobs');
    }
};
