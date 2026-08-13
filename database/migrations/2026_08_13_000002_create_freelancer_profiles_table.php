<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->text('overview')->nullable();
            $table->decimal('hourly_rate', 8, 2)->default(0.00);
            $table->enum('experience_level', ['entry', 'intermediate', 'expert'])->default('intermediate');
            $table->string('location')->nullable();
            $table->string('github_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('website')->nullable();
            $table->decimal('total_earnings', 10, 2)->default(0.00);
            $table->integer('completed_jobs_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->enum('availability_status', ['available', 'busy', 'not_available'])->default('available');
            $table->timestamps();

            $table->index('hourly_rate');
            $table->index('experience_level');
            $table->index('rating');
            $table->index('availability_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_profiles');
    }
};
