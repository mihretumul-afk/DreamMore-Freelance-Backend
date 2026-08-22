<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'in_progress', 'submitted', 'revision_requested', 'approved', 'paid'])->default('pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
