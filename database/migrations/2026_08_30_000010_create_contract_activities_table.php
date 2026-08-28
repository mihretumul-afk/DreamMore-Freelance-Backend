<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g., 'milestone.created', 'contract.paused', 'milestone.funded'
            $table->string('subject_type')->nullable(); // e.g., 'Milestone', 'Contract'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable(); // Human-readable description
            $table->json('metadata')->nullable(); // Additional context data
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['contract_id', 'created_at']);
            $table->index(['milestone_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_activities');
    }
};
