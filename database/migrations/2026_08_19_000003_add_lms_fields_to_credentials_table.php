<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            // LMS integration fields
            $table->string('verification_source')->nullable()->after('status');
            // 'dream_more_lms', 'external_manual', 'external_test'
            $table->string('lms_certificate_id')->nullable()->after('verification_source');
            $table->string('lms_course_id')->nullable()->after('lms_certificate_id');
            $table->string('lms_course_name')->nullable()->after('lms_course_id');
            $table->boolean('auto_verified')->default(false)->after('lms_course_name');
            $table->boolean('test_required')->default(false)->after('auto_verified');
            $table->enum('test_status', ['not_required', 'pending', 'passed', 'failed'])->default('not_required')->after('test_required');
            $table->text('admin_notes')->nullable()->after('test_status');

            // Prevent duplicate LMS certificate linkage
            $table->unique(['user_id', 'lms_certificate_id'], 'uq_user_lms_cert');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('uq_user_lms_cert');
            $table->dropColumn([
                'verification_source',
                'lms_certificate_id',
                'lms_course_id',
                'lms_course_name',
                'auto_verified',
                'test_required',
                'test_status',
                'admin_notes',
            ]);
        });
    }
};
