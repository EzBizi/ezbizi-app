<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-branch review finding 5 (Tasks Phase 2 final review):
 * `TaskRecurrenceWorker::relayPendingCompletions()` queries
 * `automation_events` by `event_key` + `occurred_at` WITHOUT an
 * `organization_id` predicate — it is a cross-tenant hourly sweep for a
 * single outbox marker (`task.recurrence_instance_due`), deliberately
 * `withoutGlobalScopes()` because it has no one tenant to scope to.
 *
 * The only existing covering index,
 * `automation_events_organization_id_event_key_occurred_at_index` (added by
 * `2026_08_06_060000_create_workflow_tables` under this table's former name,
 * `workflow_events`), leads with `organization_id`. A composite index is
 * only usable left-to-right; a query that never filters on the leading
 * column cannot use it at all. `automation_events` is the shared outbox for
 * every business change in every organization, retained three months — it
 * is already one of the largest tables in the schema and grows every
 * automation-emitting write across the whole app, not just Tasks — so this
 * query has been a full table scan every hour since the relay shipped.
 *
 * Adds the index the relay actually needs, `(event_key, occurred_at)`,
 * rather than rewriting the query to iterate organizations: `event_key`
 * alone is already highly selective (one marker among the whole registry),
 * so a second index is the smaller, lower-risk fix, and the existing
 * per-organization index keeps serving whatever else already relies on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_events', function (Blueprint $table) {
            $table->index(['event_key', 'occurred_at'], 'automation_events_event_key_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('automation_events', function (Blueprint $table) {
            $table->dropIndex('automation_events_event_key_occurred_at_index');
        });
    }
};
