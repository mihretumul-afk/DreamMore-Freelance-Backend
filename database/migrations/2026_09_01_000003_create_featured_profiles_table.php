<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the featured_profiles table to track paid freelancer profile visibility boosts.
     * Freelancers pay to have their profiles appear higher in search results.
     */
    public function up(): void
    {
        Schema::create('featured_profiles', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount_paid', 12, 2);
            $table->integer('duration_days');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active'); // active, expired
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']); // For expiry cron job
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('featured_profiles');
    }
};
