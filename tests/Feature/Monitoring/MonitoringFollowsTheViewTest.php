<?php

namespace Tests\Feature\Monitoring;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use App\Models\MonitoringScreenshot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Monitoring's lists follow the view the switcher is standing in (§3: "file on
 * the VIEW, not the reach").
 *
 * Jose, 2026-09-24, standing in the EzBizi company of A1-Group and looking at
 * A-1 Chimney's people and A-1 Chimney's offices: "Issue on Monitoring with
 * ownership as usual ... Organization - Company - Franchise Hierarchy."
 *
 * Every Monitoring list filtered on REACH — who this person could ever get to
 * — and none of them on the VIEW. An administrator reaches the whole
 * organization, so for him switching company changed nothing on any tab.
 *
 * ── The rule, and where it comes from ─────────────────────────────
 *
 * A row is in the view by its most specific placement, answered the way
 * ViewScope's own coversFranchise / coversCompany answer it: a franchise
 * placement by the view's franchises, a company placement by the view's
 * companies, and an ORGANIZATION placement only in the organization view
 * ("Null is the organization itself, and that is only the group view"). A
 * person is placed by their assignments, a floor plan by its own columns.
 *
 * ── Why every narrow assertion carries a control ──────────────────
 *
 * "The sibling's person is absent" passes just as well when the fixture never
 * made the sibling's person, or when the endpoint returns nothing. So each test
 * asks the same endpoint the same question from the ORGANIZATION view and
 * requires the excluded rows to be there — which also pins the other half of
 * the rule: the group view still sees everything below it.
 */
class MonitoringFollowsTheViewTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const PERMISSIONS = [
        'monitoring.view',
        'monitoring.general.view',
        'monitoring.map.view',
        'monitoring.specs.view',
        'monitoring.screenshots.view',
    ];

    /**
     * One organization, two sibling companies, and one person placed at each
     * level that matters: a franchise of the company in view, the sibling
     * company, and the organization itself.
     *
     * @return array{viewer: User, home: Company, sibling_company: Company, org: Organization, inView: User, sibling: User, orgLevel: User}
     */
    private function hierarchy(): array
    {
        $t = $this->provisionTenant('A1-Group');

        $siblingCompany = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'A-1 Chimney',
        ]));

        // Org-level: userWithRole's default placement. Reaches everything, so
        // any narrowing below is the VIEW's, not the viewer's reach.
        $viewer = $this->userWithRole($t, 'Viewer', self::PERMISSIONS);

        $inView = $this->userWithRole($t, 'Home Dispatcher', [], onlyAt: $t['franchise']);

        $sibling = $this->userWithRole($t, 'Sibling Dispatcher');
        $this->inTenant($t['org'], function () use ($sibling, $siblingCompany, $t) {
            $sibling->assignments()->delete();
            Assignment::create([
                'organization_id' => $t['org']->id,
                'user_id' => $sibling->id,
                'assigned_company_id' => $siblingCompany->id,
            ]);
        });

        $orgLevel = $this->userWithRole($t, 'Group Marketing');

        return [
            'viewer' => $viewer,
            'home' => $t['company'],
            'sibling_company' => $siblingCompany,
            'org' => $t['org'],
            'inView' => $inView,
            'sibling' => $sibling,
            'orgLevel' => $orgLevel,
        ];
    }

    /** @return string[] the user ids a list endpoint returned */
    private function userIds(array $rows): array
    {
        return array_column(array_column($rows, 'user'), 'id');
    }

    private function inCompanyView(User $viewer, Company $company, string $path): array
    {
        return $this->signedInAs($viewer)
            ->withHeader('X-EzBizi-View', 'company:'.$company->id)
            ->getJson($path)
            ->assertOk()
            ->json('data');
    }

    private function inOrganizationView(User $viewer, string $path): array
    {
        return $this->signedInAs($viewer)
            ->withHeader('X-EzBizi-View', 'organization')
            ->getJson($path)
            ->assertOk()
            ->json('data');
    }

    public function test_the_ownership_chart_shows_only_the_people_in_the_view(): void
    {
        $h = $this->hierarchy();

        $ids = $this->userIds($this->inCompanyView($h['viewer'], $h['home'], '/api/v1/monitoring/ownership'));

        $this->assertContains($h['inView']->id, $ids,
            'A person at a franchise of the company in view is in that view.');
        $this->assertNotContains($h['sibling']->id, $ids,
            'A person at a SIBLING company is not in this company\'s view. This '
            .'is the row Jose saw: A-1 Chimney\'s dispatchers under EzBizi.');
        $this->assertNotContains($h['orgLevel']->id, $ids,
            'A person placed at the organization belongs to the group view only.');

        // Control: the organization view is the whole group, both of them in it.
        $ids = $this->userIds($this->inOrganizationView($h['viewer'], '/api/v1/monitoring/ownership'));

        $this->assertContains($h['sibling']->id, $ids,
            'The sibling\'s person must be reachable from the organization view, '
            .'or the absence above proves only that the fixture never made them.');
        $this->assertContains($h['orgLevel']->id, $ids);
        $this->assertContains($h['inView']->id, $ids);
    }

    public function test_the_map_lists_only_the_offices_in_the_view(): void
    {
        $h = $this->hierarchy();

        $layout = ['grid' => ['cols' => 40, 'rows' => 28], 'walls' => [], 'nodes' => []];

        $this->inTenant($h['org'], function () use ($h, $layout) {
            MonitoringFloorPlan::create(['name' => 'Home Office', 'layout' => $layout, 'assigned_company_id' => $h['home']->id]);
            MonitoringFloorPlan::create(['name' => 'Beacon 511', 'layout' => $layout, 'assigned_company_id' => $h['sibling_company']->id]);
            MonitoringFloorPlan::create(['name' => 'Group HQ', 'layout' => $layout]);
        });

        $names = array_column($this->inCompanyView($h['viewer'], $h['home'], '/api/v1/monitoring/floor-plans'), 'name');

        $this->assertContains('Home Office', $names);
        $this->assertNotContains('Beacon 511', $names,
            'A sibling company\'s office is not on this company\'s map — the '
            .'second screenshot: Beacon 511 and 107 listed under EzBizi.');
        $this->assertNotContains('Group HQ', $names,
            'An organization-level office belongs to the group view.');

        // Control.
        $names = array_column($this->inOrganizationView($h['viewer'], '/api/v1/monitoring/floor-plans'), 'name');

        $this->assertEqualsCanonicalizing(['Home Office', 'Beacon 511', 'Group HQ'], $names,
            'The organization view sees every office below it.');
    }

    public function test_pc_specs_lists_only_the_machines_of_people_in_the_view(): void
    {
        // The people predicate is shared by PC Specs, Desktop Monitoring and
        // the chart, so this is the check that the SHARED half moved, measured
        // through a second endpoint rather than assumed from the first.
        $h = $this->hierarchy();

        foreach ([$h['inView'], $h['sibling']] as $person) {
            $this->inTenant($h['org'], fn () => MonitoredDevice::factory()->create([
                'organization_id' => $h['org']->id,
                'user_id' => $person->id,
            ]));
        }

        $ids = $this->userIds($this->inCompanyView($h['viewer'], $h['home'], '/api/v1/monitoring/devices'));

        $this->assertContains($h['inView']->id, $ids);
        $this->assertNotContains($h['sibling']->id, $ids);

        // Control.
        $ids = $this->userIds($this->inOrganizationView($h['viewer'], '/api/v1/monitoring/devices'));

        $this->assertContains($h['sibling']->id, $ids);
    }

    public function test_desktop_monitoring_lists_only_the_captures_of_people_in_the_view(): void
    {
        // Its own test rather than trusting PC Specs': the grouping bug that
        // let PC Specs leak lived at each call site, not in the shared helper,
        // so a fix measured at one site says nothing about the other.
        $h = $this->hierarchy();

        $devices = [];
        foreach (['inView', 'sibling'] as $who) {
            $devices[$who] = $this->inTenant($h['org'], function () use ($h, $who) {
                $device = MonitoredDevice::factory()->create([
                    'organization_id' => $h['org']->id,
                    'user_id' => $h[$who]->id,
                ]);
                MonitoringScreenshot::factory()->create([
                    'organization_id' => $h['org']->id,
                    'device_id' => $device->id,
                    'user_id' => $h[$who]->id,
                ]);

                return $device->id;
            });
        }

        $shown = array_column($this->inCompanyView($h['viewer'], $h['home'], '/api/v1/monitoring/screenshots'), 'device_id');

        $this->assertContains($devices['inView'], $shown);
        $this->assertNotContains($devices['sibling'], $shown);

        // Control.
        $shown = array_column($this->inOrganizationView($h['viewer'], '/api/v1/monitoring/screenshots'), 'device_id');

        $this->assertContains($devices['sibling'], $shown);
    }
}
