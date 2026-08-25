<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin roles table.
     *
     * Stores both the four built-in system roles and any custom roles
     * created by a Super Admin. System roles are protected from deletion.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // Machine-readable slug: super_admin, support_admin, etc.
            $table->string('slug', 100)->unique();

            // Human-readable display name.
            $table->string('name', 100);

            // Optional description of what this role can do.
            $table->string('description', 500)->nullable();

            // Prevents system roles from being deleted or renamed.
            $table->boolean('is_system')->default(false);

            // Soft-delete style active flag (deactivating does not lose data).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('slug');
            $table->index('is_system');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
