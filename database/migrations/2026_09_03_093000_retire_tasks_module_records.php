<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `2026_09_03_090000_drop_tasks_module_tables` dropped the tables the Tasks
 * module OWNED. It did not touch the rows Tasks had written into tables it
 * shared with every other module, and those outlive it: a permission nobody
 * can be granted, an automation whose trigger nothing will ever emit again, a
 * settings row for a screen that is gone, a notification about a task that no
 * longer exists. This removes those.
 *
 * ── Why a migration and not the sync command ─────────────────────
 *
 * `ezbizi:sync-permissions` deliberately never deletes, and its own docblock
 * says why: "a role somewhere may reference one, and silently dropping it
 * would quietly widen or narrow access with no trace. Removal is a migration
 * written by a human who has thought about the roles pointing at it." This
 * follows `2026_08_12_090000_retire_the_data_module_permission` exactly, which
 * is that same reasoning applied to the one permission the Import & export
 * module left behind. Tasks left seven.
 *
 * ── Why the roles screen is the reason, not tidiness ─────────────
 *
 * Every consumer of a permission key intersects with the module registry
 * first, so `tasks.*` already grants nothing and is already ungrantable. The
 * harm is not what it permits — it is that a role listing "Edit tasks" among
 * its permissions tells an administrator something untrue about that role, on
 * the one screen whose entire job is to say what a role does.
 *
 * ── The two kinds of automation, treated differently ─────────────
 *
 * An automation whose TRIGGER is `task.*` is dead by construction: nothing
 * emits those events any more, so it can never fire again, and it is deleted.
 * Its versions and runs go with it by `ON DELETE CASCADE`.
 *
 * An automation that merely CALLS a `task.*` action ("when a job is booked,
 * create a task") is a different thing. Its trigger is still live and the rest
 * of its actions still work; it is the customer's rule, not a Tasks record.
 * Deleting it would throw away configuration Tasks never owned, so it is
 * DISABLED with a reason naming what happened — a state the automations screen
 * already renders and an administrator can already act on.
 *
 * ── What is deliberately NOT deleted ─────────────────────────────
 *
 * `audit_events`. §10 makes the log append-only, and a migration that pruned
 * it would be the one thing the log exists to make impossible. Those rows
 * record that a person did something on a date; that stays true after the
 * module they did it in was removed.
 *
 * Attachment BLOBS on disk. The `attachments` ROWS pointing at `tasks` and
 * `task_updates` are deleted here because their targets no longer exist, but
 * this migration does not reach the filesystem — deleting bytes inside a
 * transaction that might roll back is how a rollback loses data it never
 * meant to. Those files are unreferenced afterwards and are a separate,
 * resumable sweep.
 *
 * ── Reversibility, honestly ──────────────────────────────────────
 *
 * `down()` restores the seven permission ROWS so a rollback lands on a schema
 * the old code recognises. It cannot restore which roles held them: that is
 * exactly what `permission_role` records and exactly what this deletes. Nor
 * can it restore the automations, the notifications or the settings. Restoring
 * those means a backup taken before this ran.
 */
return new class extends Migration
{
    /** The seven actions the module declared. Labels composed as PermissionCatalogue does. */
    private const PERMISSIONS = [
        'tasks.view'    => 'View tasks',
        'tasks.create'  => 'Create tasks',
        'tasks.edit'    => 'Edit tasks',
        'tasks.comment' => 'Comment tasks',
        'tasks.delete'  => 'Delete tasks',
        'tasks.export'  => 'Export tasks',
        'tasks.manage'  => 'Manage tasks',
    ];

    /** The three settings it registered. */
    private const SETTINGS = [
        'numbering.task_prefix',
        'notifications.task_enabled',
        'tasks.due_reminders_enabled',
    ];

    /** The one per-user preference, held inside the `users.preferences` JSON. */
    private const PREFERENCE = 'tasks.due_reminder_hours';

    /** Morph aliases from AppServiceProvider's map, now bound to nothing. */
    private const MORPHS = ['tasks', 'task_updates'];

    private const DISABLED_REASON =
        'The Tasks module was removed on 2026-09-02, so the task action this rule '.
        'performs no longer exists. Edit the rule to drop that action, then turn it '.
        'back on.';

    public function up(): void
    {
        // ── Permissions and their grants ─────────────────────────
        $ids = DB::table('permissions')
            ->whereIn('key', array_keys(self::PERMISSIONS))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            // Grants first. A permissions row deleted out from under its pivot
            // rows leaves them pointing at nothing, and whether that errors or
            // is silently tolerated depends on the driver.
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        // §13's kill switch writes a row keyed on the module. One left behind
        // for a module that no longer exists is a switch with nothing behind it.
        DB::table('platform_module_flags')->where('module_key', 'tasks')->delete();

        // ── Automations triggered by a task event: dead, so deleted ──
        DB::table('automations')->where('trigger_key', 'like', 'task.%')->delete();

        // ── Automations that CALL a task action: disabled, not deleted ──
        //
        // The action list lives in `automation_versions.actions` (JSON), and the
        // match is on the ACTIVE version only: an old version nobody runs is not
        // a reason to disable a rule that has since been edited away from it.
        $callers = DB::table('automations as a')
            ->join('automation_versions as v', 'v.id', '=', 'a.active_version_id')
            ->whereNull('a.deleted_at')
            ->whereNull('a.disabled_at')
            ->where('v.actions', 'like', '%"task.%')
            ->pluck('a.id');

        if ($callers->isNotEmpty()) {
            DB::table('automations')->whereIn('id', $callers)->update([
                'is_active'       => false,
                'disabled_at'     => now(),
                'disabled_reason' => self::DISABLED_REASON,
                'updated_at'      => now(),
            ]);
        }

        // ── The outbox ───────────────────────────────────────────
        // An undelivered `task.*` event would be handed to a dispatcher with no
        // definition for it. Runs cascade from the events.
        DB::table('automation_events')->where('event_key', 'like', 'task.%')->delete();

        // ── Notifications ────────────────────────────────────────
        // `source` is how a notification says what KIND of thing it is about;
        // `subject_type` is the morph. Both point at nothing now, and the client
        // renders a row that cannot be opened.
        DB::table('notifications')
            ->where(function ($q) {
                $q->where('source', 'task')
                    ->orWhereIn('subject_type', self::MORPHS);
            })
            ->delete();

        // ── Attachments pointing at dropped tables ───────────────
        DB::table('attachments')->whereIn('attachable_type', self::MORPHS)->delete();

        // ── Settings, the numbering sequence, the preference ─────
        DB::table('settings')->whereIn('key', self::SETTINGS)->delete();
        DB::table('record_sequences')->where('sequence_key', 'task')->delete();

        // JSON_REMOVE against a NULL `preferences` yields NULL, which would wipe
        // every other preference the user holds — hence the guard. The path is
        // quoted because the key itself contains a dot.
        if (DB::getDriverName() === 'mysql') {
            DB::table('users')
                ->whereNotNull('preferences')
                ->update(['preferences' => DB::raw(
                    "JSON_REMOVE(preferences, '$.\"".self::PREFERENCE."\"')"
                )]);
        }
    }

    /**
     * Puts the permission rows back, and nothing else. See the class docblock:
     * the grants, the automations, the notifications and the settings are not
     * recoverable by a migration, and a `down()` that pretended otherwise would
     * run without error and hand back nothing that was actually there.
     */
    public function down(): void
    {
        $existing = DB::table('permissions')
            ->whereIn('key', array_keys(self::PERMISSIONS))
            ->pluck('key')
            ->all();

        $rows = [];

        foreach (self::PERMISSIONS as $key => $label) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            [$module, $action] = explode('.', $key, 2);

            $rows[] = [
                'id'         => (string) Str::uuid(),
                'key'        => $key,
                'module'     => $module,
                'action'     => $action,
                'label'      => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('permissions')->insert($rows);
        }
    }
};
