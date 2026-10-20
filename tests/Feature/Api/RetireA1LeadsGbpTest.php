<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20: the A1Leads GBP tab is retired, and leaves no tick behind. */
class RetireA1LeadsGbpTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_module_is_gone_from_the_registry_and_its_grants_from_every_role(): void
    {
        $this->assertNull(app(ModuleRegistry::class)->get('a1leads_gbp'));

        // A role that held the old ticks, as production has them.
        $t = $this->provisionTenant('A1-Group');
        $role = $this->userWithRole($t, 'Bar', ['reviews.view'])->role_id;
        foreach (['view', 'reply', 'manage'] as $action) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'key' => "a1leads_gbp.{$action}", 'module' => 'a1leads_gbp',
                'action' => $action, 'label' => $action, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $id]);
        }

        // The file itself, not whatever ran when the database was built.
        (require base_path('database/migrations/2026_10_20_171508_retire_the_a1leads_gbp_module.php'))->up();

        $this->assertSame(0, DB::table('permissions')->where('key', 'like', 'a1leads_gbp.%')->count());
        $this->assertSame(1, DB::table('permission_role')->where('role_id', $role)->count(), 'only reviews.view is left');

        // And back: the saved grants are restored with the rows.
        (require base_path('database/migrations/2026_10_20_171508_retire_the_a1leads_gbp_module.php'))->down();
        $this->assertSame(4, DB::table('permission_role')->where('role_id', $role)->count(), 'the three ticks are given back');
    }
}
