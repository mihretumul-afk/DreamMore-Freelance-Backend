<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->enum('budget_type', ['fixed', 'hourly'])->default('fixed');
            $table->decimal('min_budget', 10, 2)->default(0.00);
            $table->decimal('max_budget', 10, 2)->default(0.00);
            $table->enum('experience_level', ['entry', 'intermediate', 'expert'])->default('intermediate');
            $table->enum('location_type', ['remote', 'onsite', 'hybrid'])->default('remote');
            $table->string('location')->nullable();
            $table->enum('status', ['draft', 'open', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->integer('proposals_count')->default(0);
            $table->timestamp('deadline')->nullable();
            $table->timestamps();

            $table->index('employer_id');
            $table->index('category_id');
            $table->index('status');
            $table->index('budget_type');
            $table->index('experience_level');
            $table->index('location_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_jobs');
    }
};
