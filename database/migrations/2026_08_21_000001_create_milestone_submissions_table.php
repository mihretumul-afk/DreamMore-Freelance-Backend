<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create milestone_submissions table
        Schema::create('milestone_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('milestones')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->json('links')->nullable();
            $table->enum('status', ['submitted', 'revision_requested', 'approved'])->default('submitted');
            $table->text('revision_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('milestone_id');
            $table->index('submitted_by');
            $table->index('status');
        });

        // 2. Create milestone_submission_files table
        Schema::create('milestone_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('milestone_submissions')->cascadeOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps();

            $table->index('submission_id');
            $table->index('uploader_id');
        });

        // 3. Update milestones status enum to include revision_requested (if running on MySQL/MariaDB)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('pending', 'in_progress', 'submitted', 'revision_requested', 'approved', 'paid') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_submission_files');
        Schema::dropIfExists('milestone_submissions');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestones MODIFY status ENUM('pending', 'in_progress', 'submitted', 'approved', 'paid') DEFAULT 'pending'");
        }
    }
};
