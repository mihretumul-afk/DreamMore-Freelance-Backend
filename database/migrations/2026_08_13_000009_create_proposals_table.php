<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->text('cover_letter');
            $table->decimal('bid_amount', 10, 2);
            $table->string('estimated_duration');
            $table->enum('status', ['pending', 'shortlisted', 'accepted', 'rejected', 'withdrawn'])->default('pending');
            $table->timestamps();

            $table->unique(['job_id', 'freelancer_id']);
            $table->index('job_id');
            $table->index('freelancer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
