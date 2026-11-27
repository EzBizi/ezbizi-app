<?php

namespace Tests\Feature\Scheduling;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Scheduling\SlotOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Audit 11-27: a lead's ZIP resolves to its own company's area, not a sister company's. */
class SlotOfferReachTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_lead_s_zip_resolves_to_its_own_company_s_area(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $mine = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Mine']);
            ServiceAreaZip::create(['franchise_id' => $t['franchise']->id, 'service_area_id' => $mine->id, 'zip' => '08753']);
            $sister = Company::create(['organization_id' => $t['org']->id, 'name' => 'Sister Co']);
            $branch = Franchise::create(['company_id' => $sister->id, 'name' => 'Sister NJ']);
            $theirs = ServiceArea::create(['franchise_id' => $branch->id, 'name' => 'Theirs']);
            ServiceAreaZip::create(['franchise_id' => $branch->id, 'service_area_id' => $theirs->id, 'zip' => '08753']);

            $lead = (new Lead)->forceFill(['company_id' => $sister->id]);
            $this->assertSame($theirs->id, ServiceArea::forZip('08753', SlotOffer::reach($lead))?->id);
            $lead = (new Lead)->forceFill(['franchise_id' => $t['franchise']->id]);
            $this->assertSame($mine->id, ServiceArea::forZip('08753', SlotOffer::reach($lead))?->id);
        });
    }
}
