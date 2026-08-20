<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('passing_score')->default(70); // percentage
            $table->integer('time_limit_minutes')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('skill_id');
            $table->index('is_active');
        });

        Schema::create('skill_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_test_id')->constrained('skill_tests')->cascadeOnDelete();
            $table->text('question');
            $table->json('options'); // Array of { text, is_correct }
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('skill_test_id');
        });

        Schema::create('skill_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('skill_test_id')->constrained('skill_tests')->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('credentials')->nullOnDelete();
            $table->json('answers'); // Map of question_id => selected_option_index
            $table->integer('score'); // percentage
            $table->boolean('passed');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('skill_test_id');
            $table->index('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_test_attempts');
        Schema::dropIfExists('skill_test_questions');
        Schema::dropIfExists('skill_tests');
    }
};
