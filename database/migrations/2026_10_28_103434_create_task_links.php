<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tasks a person (or ezbizi:link-duplicate-tasks) says are the same piece
 * of work (Jose, 2026-10-28): they show each other, and Done / reopen on one
 * carries to the other. One row per pair, read in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('linked_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'linked_task_id']);
            $table->index('linked_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
