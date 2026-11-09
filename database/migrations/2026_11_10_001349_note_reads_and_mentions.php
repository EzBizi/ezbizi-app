<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @mentions and unread notes (monday port, Jose 2026-11-09): when each person
 * last read a record's notes, and who each note mentioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->timestamp('last_read_at');
            $table->unique(['user_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('job_notes', fn (Blueprint $table) => $table->json('mentions')->nullable());
        Schema::table('task_updates', fn (Blueprint $table) => $table->json('mentions')->nullable());
        Schema::table('leads', fn (Blueprint $table) => $table->json('note_mentions')->nullable());
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('note_mentions'));
        Schema::table('task_updates', fn (Blueprint $table) => $table->dropColumn('mentions'));
        Schema::table('job_notes', fn (Blueprint $table) => $table->dropColumn('mentions'));
        Schema::dropIfExists('note_reads');
    }
};
