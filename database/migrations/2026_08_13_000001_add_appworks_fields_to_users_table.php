<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['freelancer', 'employer', 'admin'])->default('freelancer')->after('email');
            $table->enum('status', ['active', 'suspended', 'pending_verification'])->default('active')->after('role');
            $table->string('phone')->nullable()->after('status');
            $table->string('avatar')->nullable()->after('phone');
            $table->text('bio')->nullable()->after('avatar');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');

            $table->index('role');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn(['role', 'status', 'phone', 'avatar', 'bio', 'last_login_at']);
        });
    }
};
