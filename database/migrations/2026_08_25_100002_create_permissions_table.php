<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permissions table.
     *
     * Each row is one atomic capability, e.g. "users.suspend".
     * Grouped by category for display and filtering purposes.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            // Unique dotted slug: users.view, payments.refund, etc.
            $table->string('slug', 100)->unique();

            // Human-readable label shown in the admin UI.
            $table->string('name', 100);

            // Logical grouping: users, jobs, payments, milestones, etc.
            $table->string('group', 50)->index();

            // Optional description.
            $table->string('description', 500)->nullable();

            $table->timestamps();

            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
