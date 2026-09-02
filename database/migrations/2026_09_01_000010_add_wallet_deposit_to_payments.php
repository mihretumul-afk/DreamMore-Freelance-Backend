<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Ensure type column has an index for efficient wallet deposit queries
            if (!Schema::hasIndex('payments', 'payments_type_index')) {
                $table->index('type', 'payments_type_index');
            }

            // Add provider_response column if not present
            if (!Schema::hasColumn('payments', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('provider_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasIndex('payments', 'payments_type_index')) {
                $table->dropIndex('payments_type_index');
            }

            if (Schema::hasColumn('payments', 'provider_response')) {
                $table->dropColumn('provider_response');
            }
        });
    }
};
