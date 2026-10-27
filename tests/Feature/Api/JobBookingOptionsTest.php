<?php

namespace Tests\Feature\Api;

use App\Models\SchedulingTechnician;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The Book panel's lists are the branch's own (Jose, 2026-10-27). */
class JobBookingOptionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_lists_come_from_the_branch(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];
        [$north] = $this->inTenant($alpha['org'], function () use ($alpha, $f) {
            $north = ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'North']);
            ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'South']);
            ServiceAreaZip::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'service_area_id' => $north->id, 'zip' => '10001']);
            foreach ([['Sweep', 'Yelp'], ['Sweep', 'PPC'], ['Inspection', 'Yelp']] as $i => [$type, $source]) {
                WorkizJob::create(['franchise_id' => $f->id, 'remote_id' => "J{$i}", 'status' => 'Done', 'synced_at' => now(),
                    'payload' => ['JobType' => $type, 'JobSource' => $source, 'CustomFields' => [['Name' => 'Real Wood Y/N', 'Value' => $i ? 'Y' : 'N']]]]);
            }
            // EzBizi's own roster (Jose, 2026-10-27: EzBizi is the master).
            SchedulingTechnician::create(['franchise_id' => $f->id, 'name' => 'Ron'])->areas()->sync([$north->id]);
            SchedulingTechnician::create(['franchise_id' => $f->id, 'name' => 'Sam']);

            return [$north];
        });

        $d = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/book/options?franchise_id={$f->id}&zip=10001-1234&service_area_id={$north->id}")->assertOk()->json('data');

        $this->assertSame(['Sweep', 'Inspection'], $d['job_types'], 'most used first');
        $this->assertSame(['Yelp', 'PPC'], $d['job_sources']);
        $this->assertSame([['North', true], ['South', false]], array_map(fn ($a) => [$a['name'], $a['matches_zip']], $d['service_areas']));
        $this->assertSame([['Ron', true], ['Sam', false]], array_map(fn ($t) => [$t['name'], $t['covers_area']], $d['technicians']));
        $this->assertSame([['name' => 'Real Wood Y/N', 'type' => 'text', 'options' => ['N', 'Y']]], $d['custom_fields']);
        $this->assertNotEmpty($d['statuses']);
    }
}
