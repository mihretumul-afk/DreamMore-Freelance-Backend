<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 14 — Proposals & Bidding.
     *
     * - Add 'currency' (Ethiopian Birr by default, consistent with the rest of the app).
     * - Drop the hard (job_id, freelancer_id) unique constraint so a freelancer whose
     *   proposal was rejected or withdrawn may submit again. Duplicate *active*
     *   proposals (pending/shortlisted/accepted) are prevented at the application layer.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('currency', 10)->default('ETB')->after('bid_amount');

            $table->dropUnique(['job_id', 'freelancer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('currency');

            $table->unique(['job_id', 'freelancer_id']);
        });
    }
};
