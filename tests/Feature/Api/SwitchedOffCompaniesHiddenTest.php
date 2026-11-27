<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-10: "on task board, switched off companies should not show up,
 * that goes for everything really ... the information should still be there
 * though of course". A switched-off company, its Sub Companies and their places
 * leave every list; Settings › Organization still lists them, and their rows
 * stay.
 */
class SwitchedOffCompaniesHiddenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: Company, 2: Company, 3: Franchise, 4: Franchise} */
    private function tenantWithBeta(): array
    {
        $t = $this->provisionTenant();
        [$beta, $sub, $betaPlace, $subPlace] = $this->inTenant($t['org'], function () {
            $beta = Company::create(['name' => 'Beta Co']);
            $sub = Company::create(['name' => 'Beta Sub', 'parent_company_id' => $beta->id]);

            return [$beta, $sub,
                Franchise::create(['company_id' => $beta->id, 'name' => 'Beta F1']),
                Franchise::create(['company_id' => $sub->id, 'name' => 'Beta Sub F1'])];
        });

        return [$t, $beta, $sub, $betaPlace, $subPlace];
    }

    public function test_a_switched_off_company_leaves_every_list_and_stays_in_the_org_chart(): void
    {
        [$t, $beta, $sub, $betaPlace, $subPlace] = $this->tenantWithBeta();
        $api = fn () => $this->signedInAs($t['admin']);
        $boardCompanies = fn () => array_unique(array_column($api()->getJson('/api/v1/task-boards')->assertOk()->json('data'), 'company_id'));
        $officePlaces = fn () => array_column($api()->getJson('/api/v1/leads/office-hours')->assertOk()->json('data.places'), 'id');

        // The control: while Beta is on, its boards and places are listed.
        $this->assertContains($beta->id, $boardCompanies());
        $this->assertContains($betaPlace->id, $officePlaces());
        $this->assertContains($subPlace->id, $officePlaces());

        // Switched off at the parent only.
        $this->inTenant($t['org'], fn () => $beta->forceFill(['is_active' => false])->save());

        // Task Board: no Beta board, and Alpha's are still there.
        $this->assertNotContains($beta->id, $boardCompanies());
        $this->assertNotContains($sub->id, $boardCompanies());
        $this->assertContains($t['company']->id, $boardCompanies());
        // The boards themselves are not touched.
        $this->assertTrue($this->inTenant($t['org'], fn () => TaskBoard::query()->where('company_id', $beta->id)->exists()));

        // /me: the Sub Company reads as off too, so no picker offers it.
        $me = collect($api()->getJson('/api/v1/me')->assertOk()->json('data.companies'))->keyBy('id');
        $this->assertFalse($me[$beta->id]['is_active']);
        $this->assertFalse($me[$sub->id]['is_active']);
        $this->assertTrue($me[$t['company']->id]['is_active']);

        // A place list: neither Beta's places nor its Sub Company's.
        $places = $officePlaces();
        $this->assertNotContains($beta->id, $places);
        $this->assertNotContains($betaPlace->id, $places);
        $this->assertNotContains($subPlace->id, $places);
        $this->assertContains($t['franchise']->id, $places);

        // Settings › Organization still lists it, to switch it back on.
        $chart = collect($api()->getJson('/api/v1/companies')->assertOk()->json('companies'))->keyBy('id');
        $this->assertFalse($chart[$beta->id]['is_active']);
        $this->assertSame($sub->id, $chart[$beta->id]['sub_companies'][0]['id']);
    }

    public function test_the_rule_is_one_scope(): void
    {
        [$t, $beta, $sub, $betaPlace, $subPlace] = $this->tenantWithBeta();
        $this->inTenant($t['org'], fn () => $beta->forceFill(['is_active' => false])->save());

        $this->inTenant($t['org'], function () use ($t, $beta, $sub, $betaPlace, $subPlace) {
            $this->assertEqualsCanonicalizing([$beta->id, $sub->id], Company::query()->switchedOff()->pluck('id')->all());
            $this->assertSame([$t['company']->id], Company::query()->switchedOn()->pluck('id')->all());
            $this->assertEqualsCanonicalizing([$betaPlace->id, $subPlace->id], Franchise::query()->switchedOff()->pluck('id')->all());
            $this->assertSame([$t['franchise']->id], Franchise::query()->switchedOn()->pluck('id')->all());
            $this->assertTrue(Franchise::isSwitchedOff($subPlace->id));
            $this->assertFalse(Franchise::isSwitchedOff($t['franchise']->id));

            // A place switched off on its own, in a company that is on.
            Franchise::query()->whereKey($t['franchise']->id)->update(['is_active' => false]);
            $this->assertTrue(Franchise::isSwitchedOff($t['franchise']->id));
            $this->assertFalse(Company::isSwitchedOff($t['company']->id));
        });
    }
}
