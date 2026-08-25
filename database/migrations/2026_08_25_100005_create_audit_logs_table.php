<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * audit_logs table.
     *
     * Append-only log for all RBAC-sensitive actions. No updates/deletes
     * are ever made to this table — it is a permanent record.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who performed the action (nullable if system/CLI).
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Descriptive action name: admin.created, role.assigned, etc.
            $table->string('action', 100)->index();

            // The model type that was acted upon, e.g. 'User', 'Role'.
            $table->string('subject_type', 100)->nullable()->index();

            // The PK of the subject record.
            $table->unsignedBigInteger('subject_id')->nullable()->index();

            // Free-form context: old/new values, reason, etc.
            $table->json('context')->nullable();

            // IP address for security investigations.
            $table->string('ip_address', 45)->nullable();

            // User-Agent header (truncated to 500 chars).
            $table->string('user_agent', 500)->nullable();

            // Immutable — created once, never updated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
