<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_deposits', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('source');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->text('failure_reason')->nullable()->after('provider_reference');
        });

        Schema::table('platform_withdrawals', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('description');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->text('failure_reason')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('platform_deposits', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_reference', 'failure_reason']);
        });

        Schema::table('platform_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_reference', 'failure_reason']);
        });
    }
};
