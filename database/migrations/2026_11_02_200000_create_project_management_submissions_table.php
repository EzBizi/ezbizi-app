<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every move of a job from done pending approval to Submitted, kept as long as
 * the job (Jose, 2026-11-02: Project Management - 2's Submitted tab keeps its
 * jobs for good). The status-change events it is copied from expire after three
 * months. `project-management-2:record-submissions` fills it, the first run
 * from all of history; Submit writes its own row at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_management_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained()->cascadeOnDelete();
            // The event it came from, so a copy run twice records it once. No
            // foreign key: the event expires, the record stays.
            $table->uuid('automation_event_id')->unique();
            $table->timestamp('submitted_at');
            $table->index(['job_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_management_submissions');
    }
};
