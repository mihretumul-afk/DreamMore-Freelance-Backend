<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE credentials MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'resubmission_required') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE credentials MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
        }
    }
};
