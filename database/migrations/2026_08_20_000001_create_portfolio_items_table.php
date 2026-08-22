<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->string('project_url')->nullable();
            $table->string('image_url')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('display_order');
        });

        Schema::create('proposal_portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['proposal_id', 'portfolio_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_portfolio_items');
        Schema::dropIfExists('portfolio_items');
    }
};
