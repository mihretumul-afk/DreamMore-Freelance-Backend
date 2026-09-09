<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};