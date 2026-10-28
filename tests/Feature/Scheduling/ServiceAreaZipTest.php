<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One zip belongs to one service area in the ORGANIZATION
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2;
 * per franchise until 2026-09-25, see the migration
 * `one_zip_one_area_per_organization`).
 *
 * This is the check worth having, because it is the one carrying a promise made
 * to a person: the Availability tab lets somebody search by zip, and a zip in
 * two areas makes that search ambiguous — two answers, and no rule for choosing.
 * The ban is a unique index rather than a check in a controller precisely so it
 * cannot be walked around by a later write path, so the test goes at the
 * database.
 */
class ServiceAreaZipTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_two_areas_in_one_franchise_cannot_hold_the_same_zip(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);

        $dallas = ServiceArea::create([
            'franchise_id' => $alpha['franchise']->id,
            'name' => 'Dallas',
        ]);

        $houston = ServiceArea::create([
            'franchise_id' => $alpha['franchise']->id,
            'name' => 'Houston',
        ]);

        ServiceAreaZip::create([
            'franchise_id' => $alpha['franchise']->id,
            'service_area_id' => $dallas->id,
            'zip' => '75201',
        ]);

        // Asserting on the message and not merely the class: a QueryException
        // is also what a missing column or a bad foreign key raises, and either
        // would let this test pass while proving nothing about uniqueness.
        try {
            ServiceAreaZip::create([
                'franchise_id' => $alpha['franchise']->id,
                'service_area_id' => $houston->id,
                'zip' => '75201',
            ]);

            $this->fail('A second area took a zip the first one already holds.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase(
                'service_area_zips_company_id_zip_unique',
                $e->getMessage(),
                'Refused, but not by the uniqueness index this test is about.'
            );
        }
    }

    /**
     * TWO BRANCHES OF ONE ORGANIZATION CANNOT SHARE A ZIP — the case that
     * separates the new rule from the old one, which allowed exactly this.
     *
     * On 2026-09-25 A-1 had 66 zips on two branches' areas at once; 27 were New
     * York copies of zips New Jersey, Connecticut and others already owned, and
     * a search for any of them had two answers.
     */
    public function test_two_franchises_in_one_organization_cannot_share_a_zip(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);

        $second = Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id' => $alpha['company']->id,
            'name' => 'Nationwide',
        ]);

        $newJersey = ServiceArea::create(['franchise_id' => $alpha['franchise']->id, 'name' => 'New Jersey']);
        $newYork = ServiceArea::create(['franchise_id' => $second->id, 'name' => 'New York']);

        ServiceAreaZip::create([
            'franchise_id' => $alpha['franchise']->id,
            'service_area_id' => $newJersey->id,
            'zip' => '08753',
        ]);

        try {
            ServiceAreaZip::create([
                'franchise_id' => $second->id,
                'service_area_id' => $newYork->id,
                'zip' => '08753',
            ]);

            $this->fail('A second branch took a zip another branch already holds.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase(
                'service_area_zips_company_id_zip_unique',
                $e->getMessage(),
            );
        }
    }

    /**
     * Two ORGANIZATIONS may each hold the same zip. They are two businesses;
     * neither one's search can see the other's areas.
     */
    public function test_two_organizations_may_each_hold_the_same_zip(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        app(TenantContext::class)->set($alpha['org']);

        $alphaArea = ServiceArea::create([
            'franchise_id' => $alpha['franchise']->id,
            'name' => 'Dallas',
        ]);

        ServiceAreaZip::create([
            'franchise_id' => $alpha['franchise']->id,
            'service_area_id' => $alphaArea->id,
            'zip' => '75201',
        ]);

        app(TenantContext::class)->set($beta['org']);

        $betaArea = ServiceArea::create([
            'franchise_id' => $beta['franchise']->id,
            'name' => 'Dallas',
        ]);

        $betaZip = ServiceAreaZip::create([
            'franchise_id' => $beta['franchise']->id,
            'service_area_id' => $betaArea->id,
            'zip' => '75201',
        ]);

        $this->assertTrue($betaZip->exists);
    }

    /**
     * Settings refuses it BY NAME, before the index does — across branches.
     *
     * The index alone would answer with a 500 that tells nobody where the zip
     * lives. The clash check used to look inside one franchise only, so a zip
     * on another branch's area went straight through to that 500.
     */
    public function test_settings_names_the_other_branchs_area_that_holds_the_zip(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);

        $second = Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id' => $alpha['company']->id,
            'name' => 'Nationwide',
        ]);

        $newJersey = ServiceArea::create(['franchise_id' => $alpha['franchise']->id, 'name' => 'New Jersey']);
        $newYork = ServiceArea::create(['franchise_id' => $second->id, 'name' => 'New York']);

        ServiceAreaZip::create([
            'franchise_id' => $alpha['franchise']->id,
            'service_area_id' => $newJersey->id,
            'zip' => '08753',
        ]);

        $owner = $this->userWithRole($alpha, 'Owner', ['scheduling.view', 'scheduling.edit', 'scheduling.settings.view']);

        $message = $this->signedInAs($owner)
            ->putJson("/api/v1/scheduling/areas/{$newYork->id}/zips", ['zips' => ['08753']])
            ->assertUnprocessable()
            ->json('errors.zips.0');

        $this->assertStringContainsString('08753 is already in New Jersey', $message);
    }
}
