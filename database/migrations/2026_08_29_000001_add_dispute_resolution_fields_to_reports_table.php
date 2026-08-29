<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('admin_notes')->nullable()->after('resolution');
            $table->enum('resolution_type', ['pending', 'release_to_freelancer', 'refund_to_employer'])->default('pending')->after('admin_notes');
            $table->decimal('resolution_amount', 12, 2)->nullable()->after('resolution_type');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['admin_notes', 'resolution_type', 'resolution_amount']);
        });
    }
};
