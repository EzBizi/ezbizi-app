<?php

namespace Tests\Feature\Access;

use App\Models\Assignment;
use App\Models\CameraSite;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Roles audit 2026-11-27: somebody who reaches nothing gets the ORGANIZATION
 * view type, and companyIdsInView() answered null (no limit) for it, so a new
 * invitee with no assignment saw every company's company-level rows.
 */
class UnassignedUserSeesNoCompanyRowsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_user_with_no_assignment_sees_no_company_level_row(): void
    {
        $t = $this->provisionTenant('Alpha');
        $orgUser = $this->userWithRole($t, 'Org', [], 'org@alpha.test');
        $nobody = $this->userWithRole($t, 'Nobody', [], 'nobody@alpha.test');

        $site = $this->inTenant($t['org'], function () use ($t, $nobody) {
            Assignment::query()->where('user_id', $nobody->id)->delete();

            return CameraSite::create([
                'organization_id' => $t['org']->id,
                'company_id' => $t['company']->id,
                'name' => 'Company yard',
            ]);
        });

        $visible = fn (User $u) => $this->inTenant($t['org'], function () use ($u) {
            $u = $u->fresh();
            $this->app['auth']->forgetGuards();
            \Laravel\Sanctum\Sanctum::actingAs($u);

            return [ViewScope::forUser($u)->companyIdsInView(), CameraSite::query()->visibleTo($u)->pluck('id')->all()];
        });

        [$ids, $sites] = $visible($nobody);
        $this->assertSame([], $ids);
        $this->assertNotContains($site->id, $sites);

        // Control: organization reach is still unlimited.
        [$ids, $sites] = $visible($orgUser);
        $this->assertNull($ids);
        $this->assertContains($site->id, $sites);
    }
}
