<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->text('company_description')->nullable();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable();
            $table->string('location')->nullable();
            $table->decimal('total_spent', 10, 2)->default(0.00);
            $table->integer('posted_jobs_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->timestamps();

            $table->index('company_name');
            $table->index('industry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_profiles');
    }
};
