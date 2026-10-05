<?php

namespace Tests\Feature\Dashboard;

use App\Models\Service;
use App\Models\ServiceGroup;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\ServicesGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Services glance.
 *
 * ── Why this file exists now ──
 *
 * Services was the only glance without one, and the board-wide contract test in
 * DashboardTest only reaches a box's figures on its NON-empty branch — a fresh
 * tenant has no services, so nothing pinned these figures at all. The first
 * thing a test found was that `groups` counted the pie's 'Ungrouped' bucket as a
 * group: A-1 Chimney, 37 services with one in a real group, read "2 groups".
 *
 * ── Why there is no attention row ──
 *
 * A catalogue has no clock. Nothing in it goes overdue or breaks; a service is
 * listed or it is not. The one candidate was "N services have no price", and it
 * was rejected: a priceless service is often deliberate (Crane Rental is priced
 * per job in A1 Tools too), so a permanent row would nag about a choice. The
 * last test pins `attention` absent so adding one is a decision, not a drift.
 */
class ServicesGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function service(string $name, ?ServiceGroup $group = null): void
    {
        $this->inTenant(fn () => Service::create([
            'company_id' => $this->alpha['company']->id,
            'number' => 'SVC-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => $name,
            'group_id' => $group?->id,
        ]));
    }

    private function group(string $name): ServiceGroup
    {
        return $this->inTenant(fn () => ServiceGroup::create([
            'company_id' => $this->alpha['company']->id,
            'name' => $name,
        ]));
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $user = $this->alpha['admin'];

        return app(ServicesGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->data;
    }

    /** @return array<string, int> */
    private function figures(): array
    {
        return collect($this->data()['figures'])->pluck('value', 'key')->all();
    }

    public function test_figures_count_services_and_the_groups_they_sit_in(): void
    {
        $chimney = $this->group('Chimney');
        $garage = $this->group('Garage');
        $this->service('Sweep', $chimney);
        $this->service('Inspection', $chimney);
        $this->service('Spring', $garage);

        // Three services in two groups, and no ungrouped service to confuse it:
        // the positive control for the case below.
        $this->assertSame(['services' => 3, 'groups' => 2], $this->figures());
    }

    public function test_ungrouped_services_are_not_counted_as_a_group(): void
    {
        $chimney = $this->group('Chimney');
        $this->service('Sweep', $chimney);
        $this->service('Crane Rental');
        $this->service('Deposit');

        // The pie still shows an Ungrouped slice — it is a real share of the list.
        $this->assertContains(
            ['label' => 'Ungrouped', 'total' => 2],
            $this->data()['slices'],
        );

        // The figure must not: one group is in use, not two.
        $this->assertSame(['services' => 3, 'groups' => 1], $this->figures());
    }

    public function test_an_empty_box_sends_no_figures_and_no_attention(): void
    {
        $data = $this->data();

        $this->assertTrue($data['empty']);
        $this->assertSame('No services listed.', $data['sentence']);
        $this->assertArrayNotHasKey('figures', $data);
        $this->assertArrayNotHasKey('attention', $data);
    }

    public function test_a_listed_catalogue_still_sends_no_attention(): void
    {
        $this->service('Crane Rental');

        $data = $this->data();

        // Positive control: this is the non-empty branch, where attention would
        // live if it existed, so the absence below is about the branch it could
        // have appeared on.
        $this->assertFalse($data['empty']);
        $this->assertArrayNotHasKey('attention', $data);
    }
}
