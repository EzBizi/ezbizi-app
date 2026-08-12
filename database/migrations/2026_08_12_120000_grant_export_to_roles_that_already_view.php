<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Export is its own permission now, and nobody holds a permission that did not
 * exist yesterday.
 *
 * ── What this is protecting ──────────────────────────────────────
 *
 * `customers.export` and `jobs.export` were added to the module registry, and
 * DataController now requires them on the export paths. Deployed on their own,
 * that is a feature switching itself off: every role in every organization ships
 * without the new keys, so at the moment of the deploy the Export control in
 * Customers and in Jobs stops working for everybody except Administrators — who
 * hold the whole catalogue by construction and would be the only people left who
 * could tell you what had happened.
 *
 * Nobody asked for export to be taken away. The point of the change is that an
 * organization can now REMOVE it, deliberately, from a role. So this grants
 * `<resource>.export` to every role that already holds `<resource>.view`, which
 * is exactly the set of roles that could export that resource the day before —
 * behaviour on upgrade is identical, and the control becomes something an
 * administrator can withhold rather than something that vanished.
 *
 * ── Why the pairs are written out here ───────────────────────────
 *
 * They could be read from ResourceMap, which declares both keys per resource.
 * They are not, because a migration has to keep doing what it did the day it ran
 * — the third resource added to that map next month must not be retro-granted to
 * every role by a file dated today, and a migration that reads live application
 * code says something different every time the code changes.
 *
 * ── Roles that hold view through something else ──────────────────
 *
 * Every one of them is covered, because the view row is real rather than
 * implied: `Role::syncPermissionKeys` stores `withImpliedView($keys)`, so a role
 * granted only `customers.create` has a `customers.view` row in the pivot too.
 * Reading the pivot therefore finds every role that can reach the tab, not just
 * the ones where somebody ticked View by hand.
 *
 * Administrator roles own no pivot rows at all — `Role::hasPermission()` answers
 * true for them unconditionally — so they are correctly untouched here and
 * correctly hold the new keys anyway.
 *
 * ── Reversibility, honestly ──────────────────────────────────────
 *
 * `down()` removes the two permissions and the grants pointing at them, because
 * rolling back means code that does not know these keys, and a row that survives
 * and silently stops meaning anything is the state the workflows→automations
 * rename called worse than either keeping or dropping it.
 *
 * What a down-then-up cycle cannot preserve is a REMOVAL: an organization that
 * has since taken `customers.export` off a role would have it granted back, for
 * the same reason it was granted the first time — this file cannot tell "never
 * had it" from "was given it and then had it removed", and the pivot does not
 * record history. Worth stating rather than hiding behind an idempotency claim.
 * It is bounded: the grant is only ever handed to roles that can already read
 * the resource, and it is one tick to undo.
 */
return new class extends Migration
{
    /**
     * The view permission somebody already holds => the export permission it
     * earns, and the label that row carries.
     *
     * Labels are composed exactly as `PermissionCatalogue::labelFor()` would —
     * `ucfirst($action).' '.lcfirst($module->label)` — so a
     * `ezbizi:sync-permissions` afterwards is a no-op rather than a quiet
     * disagreement between two spellings of the same permission.
     */
    private const GRANTS = [
        'customers.view' => ['customers.export', 'customers', 'Export customers'],
        'jobs.view'      => ['jobs.export', 'jobs', 'Export jobs'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $viewKey => [$exportKey, $module, $label]) {
            $exportId = $this->ensurePermission($exportKey, $module, $label);

            $view = DB::table('permissions')->where('key', $viewKey)->first();

            // No row means no role can be holding it, so there is nobody to
            // carry across. Happens on a fresh install, where this migration
            // runs before anything has been synced or granted at all.
            if ($view === null) {
                continue;
            }

            $holders = DB::table('permission_role')
                ->where('permission_id', $view->id)
                ->pluck('role_id');

            // Re-running must not fail on the pivot's composite primary key,
            // and a partially-applied run has to be able to finish.
            $already = DB::table('permission_role')
                ->where('permission_id', $exportId)
                ->pluck('role_id')
                ->all();

            $rows = $holders
                ->reject(fn ($roleId) => in_array($roleId, $already, true))
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $exportId])
                ->all();

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as [$exportKey]) {
            $permission = DB::table('permissions')->where('key', $exportKey)->first();

            if ($permission === null) {
                continue;
            }

            // The grants first. A permissions row deleted out from under its
            // pivot rows leaves them pointing at nothing, and whether that
            // errors or is silently tolerated depends on the driver.
            DB::table('permission_role')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
        }
    }

    /**
     * The permission row, created if the deploy has not synced yet.
     *
     * Order is not guaranteed: `ezbizi:sync-permissions` may run before or after
     * migrations, and if it ran first the row is already here with this exact
     * label. Either way this returns the id the grants must point at, rather
     * than inserting a second row for a key that is unique.
     */
    private function ensurePermission(string $key, string $module, string $label): string
    {
        $existing = DB::table('permissions')->where('key', $key)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();

        DB::table('permissions')->insert([
            'id'         => $id,
            'key'        => $key,
            'module'     => $module,
            'action'     => 'export',
            'label'      => $label,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
