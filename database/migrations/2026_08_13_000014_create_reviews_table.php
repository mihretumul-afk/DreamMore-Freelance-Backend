<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewee_id')->constrained('users')->cascadeOnDelete();
            $table->enum('reviewer_role', ['employer', 'freelancer']);
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->timestamps();

            $table->unique(['contract_id', 'reviewer_id']);
            $table->index('reviewer_id');
            $table->index('reviewee_id');
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
