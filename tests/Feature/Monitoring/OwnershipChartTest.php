<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The ownership chart behind Monitoring's General tab.
 *
 * ── What these tests are really for ──────────────────────────────
 *
 * The chart pulls three things Monitoring does not own — the machine's name,
 * the person's work schedule, and where they sit — into one table. That is
 * convenient and it is exactly how a disclosure boundary gets crossed without
 * anybody deciding to: a column appears beside columns somebody already had,
 * and nothing distinguishes it.
 *
 * So the load-bearing tests here are the two about WITHHOLDING, and each
 * carries a positive control, because "the column is absent" passes just as
 * well when the endpoint returns nothing at all.
 */
class OwnershipChartTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const PATH = '/api/v1/monitoring/ownership';

    /** The chart's own grant, and nothing else. */
    private const BASE = ['monitoring.view', 'monitoring.general.view'];

    public function test_it_lists_the_people_this_actor_can_see(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Chart Viewer', self::BASE);

        $rows = $this->signedInAs($viewer)->getJson(self::PATH)
            ->assertOk()
            ->json('data');

        $names = array_column(array_column($rows, 'user'), 'name');

        $this->assertContains($viewer->name, $names,
            'The chart is a list of people, so the person reading it is on it.');
        $this->assertSame($names, collect($names)->sort()->values()->all(),
            'Ordered by name — a chart somebody scans for a colleague.');
    }

    public function test_the_machine_name_needs_the_specs_grant(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->getKey(),
            'capabilities' => ['activity', 'specs'],
            'last_seen_at' => now(),
            'specs' => ['computer_name' => 'TOULA', 'ram_gb' => 31],
        ]));

        // Without the specs grant the column is ABSENT — not empty. Empty
        // would read as "this machine has no name".
        $without = $this->userWithRole($alpha, 'No Specs', self::BASE);
        $rows = $this->signedInAs($without)->getJson(self::PATH)->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('computer_name', $row);
        }

        // Control: the SAME chart carries it for somebody holding the grant,
        // so the absence above is the gate rather than a device nobody saw.
        $with = $this->userWithRole($alpha, 'Specs', [...self::BASE, 'monitoring.specs.view']);
        $rows = $this->signedInAs($with)->getJson(self::PATH)->assertOk()->json('data');

        $names = array_column($rows, 'computer_name');
        $this->assertContains('TOULA', $names,
            'The machine name must be reachable WITH the grant, or the test above '
            .'proves only that the endpoint returns nothing.');
    }

    public function test_the_work_schedule_needs_hrs_own_grant_not_a_monitoring_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // inTenant: a write to tenant data outside a request has no
        // organization in context, and BelongsToTenant refuses rather than
        // guessing one.
        $this->inTenant($alpha['org'], fn () => $alpha['admin']
            ->forceFill(['work_schedule' => ['mon' => '09:00-17:00']])->save());

        // Monitoring's own grants, however many, never open HR's column.
        $monitoringOnly = $this->userWithRole($alpha, 'All Monitoring', [
            'monitoring.view', 'monitoring.general.view', 'monitoring.specs.view',
            'monitoring.screenshots.view', 'monitoring.cameras.view',
        ]);
        $rows = $this->signedInAs($monitoringOnly)->getJson(self::PATH)->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('schedule', $row,
                'A person holding every Monitoring grant there is must not be able '
                .'to read when colleagues are expected to work. That disclosure is '
                .'HR\'s to make.');
        }

        // Control: HR's own grant opens it, so the absence above is this gate.
        $withHr = $this->userWithRole($alpha, 'HR', [...self::BASE, 'hr.hours.view']);
        $rows = $this->signedInAs($withHr)->getJson(self::PATH)->assertOk()->json('data');

        $days = array_filter(array_column($rows, 'work_days'));
        $shifts = array_filter(array_column($rows, 'shift'));

        $this->assertNotEmpty($days,
            'With hr.hours.view the columns must be present, or the assertions '
            .'above pass for a column that never exists.');
        $this->assertNotEmpty($shifts);

        // And they are the SUMMARY, not the raw week — the whole point of the
        // change. A row still carrying the structure would satisfy notEmpty.
        $this->assertStringContainsString('Mon', $days[array_key_first($days)]);
        $this->assertStringContainsString('AM', $shifts[array_key_first($shifts)]);
    }

    public function test_desk_and_office_come_from_a_plan_the_actor_can_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $alpha['admin'];

        $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::create([
            'assigned_organization_id' => $alpha['org']->id,
            'name' => 'Main Office',
            'layout' => [
                'grid' => ['cols' => 20, 'rows' => 14],
                'walls' => [],
                'nodes' => [
                    ['id' => 'n1', 'x' => 2, 'y' => 3, 'label' => 'Front desk',
                        'user_id' => $person->getKey()],
                ],
            ],
        ]));

        $viewer = $this->userWithRole($alpha, 'Chart Viewer', [...self::BASE, 'monitoring.map.view']);
        $rows = $this->signedInAs($viewer)->getJson(self::PATH)->assertOk()->json('data');

        $row = collect($rows)->firstWhere('user.id', $person->getKey());

        $this->assertSame('Front desk', $row['desk'] ?? null);
        $this->assertSame('Main Office', $row['office'] ?? null);

        // Control: somebody with no seat on any plan reports none, rather than
        // inheriting the first desk the loop happened to read.
        $seatless = collect($rows)->firstWhere('user.id', $viewer->getKey());
        $this->assertNull($seatless['desk'] ?? null);
    }

    public function test_the_chart_refuses_without_its_tab(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $withoutTab = $this->userWithRole($alpha, 'No Chart', ['monitoring.view', 'monitoring.specs.view']);
        $this->signedInAs($withoutTab)->getJson(self::PATH)->assertForbidden();

        // Control: the same endpoint answers somebody holding the tab.
        $withTab = $this->userWithRole($alpha, 'Chart', self::BASE);
        $this->signedInAs($withTab)->getJson(self::PATH)->assertOk();
    }
}
