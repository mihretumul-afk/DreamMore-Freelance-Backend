<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('job_skills')) {
            Schema::create('job_skills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
                $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['job_id', 'skill_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_skills');
    }
};