<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 13 — Job Posting & Management.
     *
     * - Add 'closed' to the job status enum so employers can close and reopen jobs.
     * - Add 'currency' (Ethiopian Birr by default, consistent with the rest of the app).
     * - Add 'published_at' so clients can show when a job was published / republished.
     */
    public function up(): void
    {
        Schema::table('marketplace_jobs', function (Blueprint $table) {
            $table->enum('status', ['draft', 'open', 'in_progress', 'completed', 'cancelled', 'closed'])
                ->default('open')
                ->change();

            $table->string('currency', 10)->default('ETB')->after('max_budget');
            $table->timestamp('published_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_jobs', function (Blueprint $table) {
            $table->dropColumn(['currency', 'published_at']);

            $table->enum('status', ['draft', 'open', 'in_progress', 'completed', 'cancelled'])
                ->default('open')
                ->change();
        });
    }
};
