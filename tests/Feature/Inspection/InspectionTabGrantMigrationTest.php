<?php

namespace Tests\Feature\Inspection;

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The safety migration actually grants, rather than merely running.
 *
 * A migration that executes and inserts nothing reports exactly the same
 * "DONE" as one that does the job, which is why this is asserted on the pivot
 * rather than on the console. The whole promise of per-tab permissions is that
 * nobody loses a tab they had this morning; if this inserted nothing, every
 * existing role would keep `inspection.view`, hold none of the three tab keys,
 * and lose all three tabs at once with no error anywhere.
 */
class InspectionTabGrantMigrationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TABS = [
        'inspection.new.view',
        'inspection.reports.view',
        'inspection.builder.view',
    ];

    public function test_a_role_holding_the_module_is_given_all_three_tabs(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Office', ['inspection.view']);

        $roleId = $this->inTenant($tenant['org'], fn () => $user->fresh()->role_id);

        // The state this migration exists for: the module's view, and none of
        // the tabs. Cleared explicitly rather than assumed, because the fixture
        // helper's grants are not this test's subject.
        $this->clearTabs($roleId);
        $this->assertSame(0, $this->tabsHeldBy($roleId), 'the fixture must start without them');

        $this->runMigration();

        $this->assertSame(3, $this->tabsHeldBy($roleId), 'every tab the role could already open');
    }

    /**
     * A role WITHOUT the module gets nothing, which is the other half.
     *
     * Without this, a migration granting the three keys to every role in the
     * database would pass the test above and quietly widen access for people
     * who never had Inspection at all.
     */
    public function test_a_role_without_the_module_is_given_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $outsider = $this->userWithRole($tenant, 'Driver', ['fleet.view']);

        $roleId = $this->inTenant($tenant['org'], fn () => $outsider->fresh()->role_id);
        $this->clearTabs($roleId);

        $this->runMigration();

        $this->assertSame(0, $this->tabsHeldBy($roleId), 'somebody with no Inspection gains no Inspection tab');
    }

    /** Running it twice inserts nothing the second time and throws nothing. */
    public function test_it_is_idempotent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Office', ['inspection.view']);
        $roleId = $this->inTenant($tenant['org'], fn () => $user->fresh()->role_id);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(3, $this->tabsHeldBy($roleId), 'a second run must not duplicate or throw');
    }

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_17_200000_grant_existing_roles_the_three_inspection_tabs.php',
        );

        $migration->up();
    }

    private function clearTabs(string $roleId): void
    {
        $ids = Permission::query()->whereIn('key', self::TABS)->pluck('id');

        DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $ids)->delete();
    }

    private function tabsHeldBy(string $roleId): int
    {
        $ids = Permission::query()->whereIn('key', self::TABS)->pluck('id');

        return DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $ids)
            ->count();
    }
}
