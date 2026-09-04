<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The EzBizi Tasks module was removed from the codebase on 2026-09-02, to be
 * rebuilt from scratch. This drops every table it owned, plus the one column
 * it had added to the shared `notifications` table, so a database that has
 * already run the five Tasks migrations ends up with no trace of them.
 *
 * `dropIfExists` throughout: a database that never ran those migrations (a
 * fresh install after this change) has none of these tables, and this must
 * be a no-op for it rather than an error.
 *
 * ── Order: exactly the reverse of the five migrations that built this,
 * each migration's own tables dropped in its own down()'s order ──────
 *
 * The five migrations were additive-only (2026_08_25_170000 through
 * 2026_08_29_100000) — each one only ever referenced tables created by
 * itself or an EARLIER migration, never a later one. So undoing them in
 * reverse chronological order, in each one's own already-correct internal
 * order, is guaranteed FK-safe: nothing is dropped before every table that
 * references it is already gone. `tasks` itself is dropped whole, so its
 * `recurrence` column and the phase-two index on it need no separate
 * statement — see 2026_08_29_100000_add_task_phase_two_storage.php.
 *
 * ── Why there is no real down() ────────────────────────────────────
 *
 * This deletes data. There is no migration that can put erased board
 * structures, tasks, updates or attachments back, so a down() that recreated
 * empty tables would be a lie shaped like a rollback — it would run without
 * error and hand back nothing that was actually there before. Restoring this
 * data means restoring the database from a backup taken before this
 * migration ran, not running a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── undo 2026_08_29_100000_add_task_phase_two_storage.php ────
        Schema::dropIfExists('task_recurrence_occurrences');
        Schema::dropIfExists('task_dependencies');

        // ── undo 2026_08_25_173000_create_task_view_and_delivery_tables.php ──
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS task_views_main_marker_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS task_views_main_permanent');
        }

        if (Schema::hasColumn('notifications', 'delivery_key')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropUnique('notifications_delivery_unique');
                $table->dropColumn('delivery_key');
            });
        }

        Schema::dropIfExists('task_import_sessions');
        Schema::dropIfExists('task_notification_intents');
        Schema::dropIfExists('task_due_reminder_occurrences');
        Schema::dropIfExists('task_overdue_occurrences');
        Schema::dropIfExists('task_view_preferences');
        Schema::dropIfExists('task_views');

        // ── undo 2026_08_25_172000_create_task_collaboration_tables.php ──
        Schema::dropIfExists('task_record_links');
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_update_attachment_candidates');
        Schema::dropIfExists('task_update_mentions');
        Schema::dropIfExists('task_update_versions');
        Schema::dropIfExists('task_updates');
        Schema::dropIfExists('task_watch_preferences');
        Schema::dropIfExists('task_collaborators');

        // ── undo 2026_08_25_171000_create_task_core_tables.php ───────
        Schema::dropIfExists('task_column_files');
        Schema::dropIfExists('task_column_labels');
        Schema::dropIfExists('task_column_people');
        Schema::dropIfExists('task_column_values');
        Schema::dropIfExists('tasks');

        // ── undo 2026_08_25_170000_create_task_board_tables.php ──────
        Schema::dropIfExists('task_column_options');
        Schema::dropIfExists('task_column_definitions');
        Schema::dropIfExists('task_priority_options');
        Schema::dropIfExists('task_status_options');
        Schema::dropIfExists('task_core_column_settings');
        Schema::dropIfExists('task_groups');
        Schema::dropIfExists('task_board_preferences');
        Schema::dropIfExists('task_board_members');
        Schema::dropIfExists('task_boards');
        Schema::dropIfExists('task_folders');
    }

    /**
     * No real down(). See the class docblock: the data this drops cannot be
     * recreated by a migration, so a down() that recreated empty tables
     * would claim a rollback this cannot actually perform.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'This migration drops the Tasks module\'s tables and data. There is no '.
            'migration that can restore what it deletes — restore from a backup taken '.
            'before it ran instead of rolling it back.'
        );
    }
};
