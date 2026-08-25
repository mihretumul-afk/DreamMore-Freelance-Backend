<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * admin_user_roles pivot.
     *
     * Assigns admin roles to users whose `role` column is 'admin'.
     * Named 'admin_user_roles' (not 'user_roles') to avoid any
     * future conflict with marketplace-level role concepts.
     *
     * A user can hold multiple admin roles simultaneously.
     */
    public function up(): void
    {
        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            // Who assigned this role (nullable for seeded Super Admin bootstrap).
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->useCurrent();

            // A user can only hold a given admin role once.
            $table->unique(['user_id', 'role_id'], 'admin_user_role_unique');

            $table->index('user_id');
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_roles');
    }
};
