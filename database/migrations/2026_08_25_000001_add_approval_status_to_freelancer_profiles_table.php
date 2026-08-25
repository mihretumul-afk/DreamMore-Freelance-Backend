<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                  ->default('approved')
                  ->after('user_id');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->text('rejection_reason')->nullable()->after('approved_at');

            $table->index('approval_status');
        });

        // Existing freelancers were already visible — mark them as approved.
        DB::table('freelancer_profiles')
            ->whereNull('approval_status')
            ->update(['approval_status' => 'approved', 'approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approved_at', 'rejection_reason']);
        });
    }
};
