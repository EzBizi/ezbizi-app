<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 storage, additive only (ACTIVE-CONSTRAINTS.md): `tasks` gains one
 * nullable column, and two new tables are created. Nothing existing is
 * renamed, retyped, or made non-nullable (Tasks Phase 2 design §16).
 *
 * `tasks.start_on` already exists — Phase 1 created it
 * (2026_08_25_171000_create_task_core_tables.php) beside `due_on`. Phase 2
 * needs no new start-date column; only `recurrence` and the new
 * `(board_id, start_on, due_on)` index are added here.
 *
 * `task_dependencies` repeats the compound organization/board identity every
 * other board child uses, so a cross-tenant or cross-board edge is rejected by
 * MySQL rather than by whoever wrote the query (design §9). It names
 * organization_id, board_id, blocker_task_id, blocked_task_id, created_by
 * explicitly (task-1 brief).
 *
 * `task_recurrence_occurrences` is a marker table shaped like
 * `task_overdue_occurrences`: unique (task_id, occurrence_date) is its entire
 * idempotency story for a retried or overlapping worker run. Its column list
 * omits board_id on purpose (task-1 brief) — it is always about one series
 * task, never an edge between two, so a single-column reference to
 * `tasks.id` is enough and does not need a new candidate key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('recurrence')->nullable()->after('due_on');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['board_id', 'start_on', 'due_on'], 'tasks_board_start_due_index');
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('board_id');
            $table->uuid('blocker_task_id');
            $table->uuid('blocked_task_id');
            $table->uuid('created_by');
            $table->timestamps();

            $table->unique(['blocker_task_id', 'blocked_task_id'], 'task_dependencies_pair_unique');
            $table->index('blocked_task_id', 'task_dependencies_blocked_index');
            $table->index('blocker_task_id', 'task_dependencies_blocker_index');

            $table->foreign(['organization_id', 'board_id'], 'task_dependencies_board_foreign')
                ->references(['organization_id', 'id'])->on('task_boards')->restrictOnDelete();
            $table->foreign(['organization_id', 'board_id', 'blocker_task_id'], 'task_dependencies_blocker_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign(['organization_id', 'board_id', 'blocked_task_id'], 'task_dependencies_blocked_foreign')
                ->references(['organization_id', 'board_id', 'id'])->on('tasks')->cascadeOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'task_dependencies_created_by_foreign')
                ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('task_recurrence_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('task_id');
            $table->date('occurrence_date');
            $table->uuid('created_task_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'occurrence_date'], 'task_recurrence_occurrences_task_date_unique');
            $table->foreign('task_id', 'task_recurrence_occurrences_task_foreign')
                ->references('id')->on('tasks')->restrictOnDelete();
            $table->foreign('created_task_id', 'task_recurrence_occurrences_created_task_foreign')
                ->references('id')->on('tasks')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `task_dependencies` ADD CONSTRAINT `task_dependencies_not_self_check` CHECK (`blocker_task_id` <> `blocked_task_id`)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_recurrence_occurrences');
        Schema::dropIfExists('task_dependencies');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_board_start_due_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('recurrence');
        });
    }
};
