<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One zip belongs to one service area, per franchise
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2).
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
                'service_area_zips_franchise_id_zip_unique',
                $e->getMessage(),
                'Refused, but not by the uniqueness index this test is about.'
            );
        }
    }

    /**
     * The ban is per franchise, not global. Two franchises working the same
     * metro is ordinary — a shared zip between them is not a conflict, because
     * neither one's search can return the other's area.
     */
    public function test_two_franchises_may_each_hold_the_same_zip(): void
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
}
