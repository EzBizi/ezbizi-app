<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Project Management client texts a job has had (Jose, 2026-11-17), so
 * each goes once: project_link, scheduled, day_before, complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_board_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestamp('created_at')->nullable();
            $table->unique(['job_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_board_messages');
    }
};
