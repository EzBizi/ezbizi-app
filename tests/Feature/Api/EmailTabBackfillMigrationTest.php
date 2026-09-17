<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Email tab backfill, run as the FILE rather than as an idea of it.
 *
 * ── WHY THIS EXISTS, AND IT IS NOT A HYPOTHETICAL ────────────────
 *
 * On 2026-09-17 this migration failed against production and aborted a
 * release mid-run:
 *
 *     Target class [App\Support\Access\PermissionCatalogue] does not exist.
 *
 * One word. The class lives in `App\Support\Modules`, eight other migrations
 * import it correctly, and mine was the only one that did not. The whole
 * module was green — 197 backend tests, 2061 client tests — because **a
 * migration's class references resolve when it RUNS, and nothing in the suite
 * ran it.** `RefreshDatabase` runs migrations, but a fresh test database has
 * no role holding `email.view`, so `up()` returned at its first guard before
 * reaching the line that names the class.
 *
 * That is the shape worth remembering: the migration DID execute in every test
 * run, and executed the wrong half of itself every time.
 *
 * ── AND WHY IT REQUIRES THE FILE ─────────────────────────────────
 *
 * A test that re-creates what the migration does proves the idea works. It
 * cannot fail on a typo in the file, which is the only way this has ever gone
 * wrong. So the file is `require`d and its `up()` invoked — the artefact that
 * ships, through the container that resolves its imports.
 *
 * The shape is the Reports session's, who hit the sibling of this an hour
 * earlier on the same evening.
 */
class EmailTabBackfillMigrationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FILE = 'database/migrations/'
        .'2026_09_17_140000_grant_email_tabs_to_roles_that_already_held_email.php';

    private function migration(): Migration
    {
        return require base_path(self::FILE);
    }

    /**
     * A role that held the module before the split comes out holding both tabs.
     *
     * This is the case production had and the test database does not get for
     * free: `up()` looks up `email.view`, finds the roles that hold it, and
     * only then touches the catalogue. With no such role it returns early and
     * every line that could fail is skipped.
     */
    public function test_it_gives_both_tabs_to_a_role_that_already_held_email(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $role = $this->inTenant($t['org'], function () use ($t) {
            $role = Role::create([
                'organization_id' => $t['org']->id,
                'name'            => 'Dispatcher',
            ]);

            // The pre-split state, exactly: the module key and neither tab.
            DB::table('permission_role')->insert([
                'role_id'       => $role->getKey(),
                'permission_id' => Permission::query()->where('key', 'email.view')->value('id'),
            ]);

            return $role;
        });

        $this->assertSame([], $this->tabKeysOf($role), 'fixture already had the tabs');

        $this->migration()->up();

        $this->assertSame(
            ['email.inbox.view', 'email.settings.view'],
            $this->tabKeysOf($role),
        );
    }

    /**
     * Running it twice writes one row, and running it on a role that holds
     * neither writes none.
     *
     * Two claims the docblock in the migration makes about itself: that it is
     * a no-op in every ordering, and that it widens nobody who did not already
     * hold the module. Both are `insertOrIgnore` and a `where`, and both are
     * the kind of thing that is true until somebody edits the query.
     */
    public function test_it_is_repeatable_and_widens_nobody_else(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        [$holder, $stranger] = $this->inTenant($t['org'], function () use ($t) {
            $holder = Role::create(['organization_id' => $t['org']->id, 'name' => 'Dispatcher']);
            $stranger = Role::create(['organization_id' => $t['org']->id, 'name' => 'Driver']);

            DB::table('permission_role')->insert([
                'role_id'       => $holder->getKey(),
                'permission_id' => Permission::query()->where('key', 'email.view')->value('id'),
            ]);

            return [$holder, $stranger];
        });

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            ['email.inbox.view', 'email.settings.view'],
            $this->tabKeysOf($holder),
            'the second run duplicated or lost something',
        );

        // Nobody who did not hold Email is now holding a piece of it.
        $this->assertSame([], $this->tabKeysOf($stranger));
    }

    /**
     * @return list<string>
     */
    private function tabKeysOf(Role $role): array
    {
        return app(TenantContext::class)->run($role->organization, function () use ($role) {
            $keys = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.role_id', $role->getKey())
                ->whereIn('permissions.key', ['email.inbox.view', 'email.settings.view'])
                ->pluck('permissions.key')
                ->all();

            sort($keys);

            return $keys;
        });
    }
}
