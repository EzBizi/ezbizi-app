<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Workflow" becomes "automation", all the way down (plan §5.5).
 *
 * The product calls this automation and always did — §5.5 is titled for it, the
 * standing rule is written in terms of it, and the owner asks for it by that
 * name. Only the schema, the classes and the permission keys said "workflow".
 * This codebase's convention is that the word in the database is the word on
 * the screen, so one of the two had to move.
 *
 * ── Why now, and why it is free ──────────────────────────────────
 *
 * All four tables were EMPTY — verified 0 rows in production on 2026-08-10, as
 * were notifications and permission_role. Nothing has ever been automated yet.
 * This is the one moment the rename costs nothing but names.
 *
 * ── What a plain RENAME TABLE does NOT do ────────────────────────
 *
 * MySQL carries indexes and foreign keys across a table rename under their
 * ORIGINAL names. Left alone, `automation_runs` would still hold
 * `workflow_runs_idempotency` and `workflow_runs_workflow_same_org` — the two
 * constraints that ARE the idempotency and the same-tenant guarantees. Someone
 * grepping for them by the new name would find nothing and conclude they had
 * been lost. So each one is renamed with its table.
 *
 * The names below were read out of information_schema rather than derived from
 * the two migrations that created them, because MySQL silently declines to
 * build an index for a foreign key when an existing one already covers the same
 * leading columns. That happened six times here — `workflows_org_id_unique`
 * absorbed the organization_id key, and so on — so a list produced by following
 * Laravel's naming convention would have tried to rename objects that do not
 * exist. What makes the substitution safe is that str_replace rewrites EVERY
 * occurrence: five of these names contain "workflow" twice —
 * `workflow_runs_workflow_id_foreign` and its four siblings — and both halves
 * have to move. A replace-the-first-one-only would silently mangle those five.
 *
 * MySQL has no RENAME CONSTRAINT, so foreign keys are dropped first and re-added
 * last. Their backing indexes survive the drop and are renamed in between, which
 * is why re-adding them creates no new ones.
 *
 * ── The permission keys are the interesting part ─────────────────
 *
 * A module key is a permission PREFIX, so renaming it renames four grantable
 * permissions. `ezbizi:sync-permissions` is firstOrCreate and deliberately
 * never deletes — see its own docblock — so running it after this change would
 * have INSERTED four `automations.*` rows and left four orphaned `workflows.*`
 * rows behind. A role holding the old ones would then carry grants that no code
 * ever consults again: the row survives and silently stops meaning anything,
 * which is worse than either keeping or dropping it.
 *
 * Updating those rows IN PLACE keeps their ids, so `permission_role` — which
 * joins on id, not on key — needs no migration and no role loses anything. The
 * sync command afterwards is a no-op, which is how it should read.
 *
 * `platform_module_flags.module_key` moves for the mirror reason:
 * ModuleAvailability fails OPEN for a module with no flag row, so a DISABLED
 * flag stranded under the old key would have quietly re-enabled the module for
 * every organization.
 */
return new class extends Migration
{
    /**
     * Everything below is written in its workflow-era form and translated per
     * direction, because down() has to undo exactly what up() did.
     */
    private const TABLES = [
        'workflows',
        'workflow_versions',
        'workflow_events',
        'workflow_runs',
    ];

    /** table => columns carrying the word. `notifications` keeps its own name. */
    private const COLUMNS = [
        'workflow_versions' => ['workflow_id'],
        'workflow_runs'     => ['workflow_id', 'workflow_version_id'],
        'notifications'     => ['workflow_id'],
    ];

    /** table => index names, as information_schema reports them. */
    private const INDEXES = [
        'workflows' => [
            'workflows_org_id_unique',
            'workflows_organization_id_trigger_key_is_active_index',
        ],
        'workflow_versions' => [
            'workflow_versions_same_org',
            'workflow_versions_workflow_id_version_unique',
        ],
        'workflow_events' => [
            'workflow_events_org_id_unique',
            'workflow_events_root_event_id_index',
            'workflow_events_queued_at_delivered_at_index',
            'workflow_events_delivered_at_failed_at_available_at_index',
            'workflow_events_organization_id_event_key_occurred_at_index',
        ],
        'workflow_runs' => [
            'workflow_runs_idempotency',
            'workflow_runs_event_same_org',
            'workflow_runs_workflow_id_foreign',
            'workflow_runs_organization_id_workflow_id_started_at_index',
        ],
    ];

    /**
     * table => [constraint name => [local columns, referenced table, referenced
     * columns]]. Dropped before the rename, re-added after it.
     */
    private const FOREIGN_KEYS = [
        'workflows' => [
            'workflows_organization_id_foreign' => [['organization_id'], 'organizations', ['id']],
        ],
        'workflow_events' => [
            'workflow_events_organization_id_foreign' => [['organization_id'], 'organizations', ['id']],
        ],
        'workflow_versions' => [
            'workflow_versions_workflow_id_foreign' => [['workflow_id'], 'workflows', ['id']],
            'workflow_versions_same_org' => [['organization_id', 'workflow_id'], 'workflows', ['organization_id', 'id']],
        ],
        'workflow_runs' => [
            'workflow_runs_organization_id_foreign' => [['organization_id'], 'organizations', ['id']],
            'workflow_runs_workflow_id_foreign'     => [['workflow_id'], 'workflows', ['id']],
            'workflow_runs_event_id_foreign'        => [['event_id'], 'workflow_events', ['id']],
            'workflow_runs_workflow_same_org' => [['organization_id', 'workflow_id'], 'workflows', ['organization_id', 'id']],
            'workflow_runs_event_same_org' => [['organization_id', 'event_id'], 'workflow_events', ['organization_id', 'id']],
        ],
    ];

    public function up(): void
    {
        $this->apply(toAutomations: true);
    }

    public function down(): void
    {
        $this->apply(toAutomations: false);
    }

    /**
     * One body, run in either direction.
     *
     * `$was` gives a name as the database currently holds it and `$becomes`
     * gives what it should end up as; going backwards simply swaps the two.
     * Writing down() out longhand instead means two lists that have to be kept
     * in agreement by hand, and the one that rots is always the rollback.
     */
    private function apply(bool $toAutomations): void
    {
        $automated = fn (string $n) => str_replace('workflow', 'automation', $n);
        $verbatim  = fn (string $n) => $n;

        $was     = $toAutomations ? $verbatim : $automated;
        $becomes = $toAutomations ? $automated : $verbatim;

        // SQLite cannot rename an index and the harden migration already skips
        // every constraint here on any driver but MySQL — so elsewhere there is
        // nothing to rename in the first place, and the tables and columns below
        // are the whole job.
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($mysql) {
            foreach (self::FOREIGN_KEYS as $table => $keys) {
                $this->onTable($was($table), function (Blueprint $t) use ($keys, $was) {
                    foreach (array_keys($keys) as $name) {
                        $t->dropForeign($was($name));
                    }
                });
            }
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($was($table)) && ! Schema::hasTable($becomes($table))) {
                Schema::rename($was($table), $becomes($table));
            }
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($becomes($table), $was($column))) {
                    $this->onTable(
                        $becomes($table),
                        fn (Blueprint $t) => $t->renameColumn($was($column), $becomes($column)),
                    );
                }
            }
        }

        if ($mysql) {
            foreach (self::INDEXES as $table => $names) {
                $this->onTable($becomes($table), function (Blueprint $t) use ($names, $was, $becomes) {
                    foreach ($names as $name) {
                        $t->renameIndex($was($name), $becomes($name));
                    }
                });
            }

            foreach (self::FOREIGN_KEYS as $table => $keys) {
                $this->onTable($becomes($table), function (Blueprint $t) use ($keys, $becomes) {
                    foreach ($keys as $name => [$columns, $on, $references]) {
                        $t->foreign(array_map($becomes, $columns), $becomes($name))
                            ->references($references)->on($becomes($on))->cascadeOnDelete();
                    }
                });
            }
        }

        $this->movePermissions(
            from: $was('workflows'),
            to: $becomes('workflows'),
            label: $toAutomations ? 'Automations' : 'Workflows & automations',
        );

        $this->moveWidgetKeys($was('workflows.health'), $becomes('workflows.health'));

        // Carried on the notification row so the bell can tag "a rule did this".
        // Old rows left saying 'workflow' would go untagged in a UI that has
        // stopped looking for the word.
        DB::table('notifications')
            ->where('source', $was('workflow'))
            ->update(['source' => $becomes('workflow')]);
    }

    /** Schema::table, but silent when the table is not there — down() after a partial up(). */
    private function onTable(string $table, callable $build): void
    {
        if (Schema::hasTable($table)) {
            Schema::table($table, $build);
        }
    }

    /**
     * The permission catalogue, moved rather than re-seeded.
     *
     * Moved in place — an UPDATE, not delete-and-reseed — so ids survive and
     * every `permission_role` grant comes with them. Re-seeding would hand each
     * role a set of permissions it no longer holds, silently.
     *
     * ── When the destination already exists ──────────────────────
     *
     * That happens if `ezbizi:sync-permissions` runs on the new code BEFORE this
     * migration, which DEPLOY.md's ordering avoids but nothing enforces. The
     * first version of this skipped such a row, and skipping is not safe: it is
     * only crash-safe. Measured on a production-shaped database — sync first,
     * then migrate — the result was 35 permissions (4 new plus 4 orphaned
     * originals) and a role still holding `workflows.view` and `workflows.edit`,
     * which is to say holding NOTHING, because the app has stopped asking for
     * those keys. Verbatim the outcome this file's own header calls worse than
     * either keeping or dropping it.
     *
     * So the grants are re-pointed at the surviving row and the stale one is
     * deleted. Duplicates on the pivot are dropped first, because a role could
     * legitimately hold both by then and the pair is unique.
     */
    private function movePermissions(string $from, string $to, string $label): void
    {
        foreach (DB::table('permissions')->where('module', $from)->get() as $permission) {
            $key = "{$to}.{$permission->action}";

            $existing = DB::table('permissions')->where('key', $key)->first();

            if ($existing !== null) {
                // Any role already holding the destination does not need the
                // stale row pointed at it as well.
                $alreadyHave = DB::table('permission_role')
                    ->where('permission_id', $existing->id)
                    ->pluck('role_id');

                DB::table('permission_role')
                    ->where('permission_id', $permission->id)
                    ->whereIn('role_id', $alreadyHave)
                    ->delete();

                DB::table('permission_role')
                    ->where('permission_id', $permission->id)
                    ->update(['permission_id' => $existing->id]);

                DB::table('permissions')->where('id', $permission->id)->delete();

                continue;
            }

            DB::table('permissions')->where('id', $permission->id)->update([
                'key'    => $key,
                'module' => $to,
                // Composed exactly as SyncPermissions::label() would, so the next
                // sync agrees with this instead of disagreeing quietly forever.
                'label'      => ucfirst($permission->action).' '.lcfirst($label),
                'updated_at' => now(),
            ]);
        }

        DB::table('platform_module_flags')
            ->where('module_key', $from)
            ->update(['module_key' => $to, 'updated_at' => now()]);
    }

    /**
     * Saved dashboard layouts name their panels by key.
     *
     * `workflows.health` became `automations.health`, and DashboardController
     * skips an unrecognised key SILENTLY by design — so a layout saved before
     * this deploy would simply lose the panel, with nothing said. The same class
     * of stranded string as the permission keys above, in the one table nobody
     * thinks of as schema.
     *
     * Text replacement on the JSON column rather than decode-and-rewrite: the
     * key appears only inside `"workflows.health"`, and the alternative walks
     * every user's preferences to change one substring.
     */
    private function moveWidgetKeys(string $from, string $to): void
    {
        DB::table('users')
            ->whereNotNull('preferences')
            ->where('preferences', 'like', '%"'.$from.'"%')
            ->update([
                'preferences' => DB::raw(
                    "REPLACE(preferences, ".DB::getPdo()->quote('"'.$from.'"').", "
                    .DB::getPdo()->quote('"'.$to.'"').")"
                ),
            ]);
    }
};
