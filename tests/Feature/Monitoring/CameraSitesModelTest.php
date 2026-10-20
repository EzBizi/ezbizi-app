<?php

namespace Tests\Feature\Monitoring;

use App\Models\CameraSite;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoringCamera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Camera sites (2026-10-20 camera bridge, phase 1): a physical place whose
 * cameras reach EzBizi through a bridge PC. A site is filed under a company or
 * franchise and its cameras follow it through the company switcher, like the
 * rest of Monitoring. A camera with no site (a Ring camera, a public URL) is
 * unchanged.
 */
class CameraSitesModelTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** A second company with its own franchise, beside the tenant's own. */
    private function elsewhere(array $tenant): Franchise
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $company = Company::create(['organization_id' => $tenant['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });
    }

    private function siteWithCamera(array $tenant, string $name, ?Franchise $at): MonitoringCamera
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $at) {
            $site = CameraSite::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $at?->company_id,
                'franchise_id' => $at?->id,
                'name' => $name,
            ]);

            return MonitoringCamera::create([
                'organization_id' => $tenant['org']->id,
                'label' => "{$name} dock",
                'protocol' => 'mjpeg',
                'url' => '',
                'source' => 'bridge',
                'site_id' => $site->id,
                'channel' => '1',
            ]);
        });
    }

    public function test_a_site_camera_follows_its_sites_place_through_the_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $other = $this->elsewhere($alpha);

        $mine = $this->siteWithCamera($alpha, 'Hialeah', $alpha['franchise']);
        $theirs = $this->siteWithCamera($alpha, 'Boston', $other);

        $branch = $this->userWithRole($alpha, 'Branch', ['monitoring.cameras.view'], onlyAt: $alpha['franchise']);

        $seen = $this->inTenant($alpha['org'], fn () => MonitoringCamera::query()
            ->visibleTo($branch)->pluck('id')->all());

        $this->assertContains($mine->id, $seen);
        $this->assertNotContains($theirs->id, $seen, "Another company's site stays out of a branch view.");

        // The organization's administrator, in the group view, sees both.
        $all = $this->inTenant($alpha['org'], fn () => MonitoringCamera::query()
            ->visibleTo($alpha['admin'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $all);
    }

    public function test_a_siteless_camera_is_unchanged(): void
    {
        // The Ring cameras: an organization asset with a URL and no site.
        $alpha = $this->provisionTenant('Alpha');
        $ring = $this->inTenant($alpha['org'], fn () => MonitoringCamera::create([
            'organization_id' => $alpha['org']->id,
            'label' => 'Ring · Downstairs', 'protocol' => 'mjpeg', 'url' => 'https://example.test/x',
        ]));

        $branch = $this->userWithRole($alpha, 'Branch', ['monitoring.cameras.view'], onlyAt: $alpha['franchise']);
        $seen = $this->inTenant($alpha['org'], fn () => MonitoringCamera::query()
            ->visibleTo($branch)->pluck('id')->all());

        $this->assertContains($ring->id, $seen);
        $this->assertSame('url', $ring->fresh()->source);
    }

    public function test_a_site_is_online_for_two_minutes_after_a_checkin(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $site = $this->inTenant($alpha['org'], fn () => CameraSite::create([
            'organization_id' => $alpha['org']->id, 'name' => 'Hialeah',
        ]));

        $this->assertFalse($site->online(), 'Never checked in is offline.');

        $this->inTenant($alpha['org'], fn () => $site->forceFill(['last_checkin_at' => now()])->save());
        $this->assertTrue($this->inTenant($alpha['org'], fn () => $site->fresh()->online()));

        $this->travel(121)->seconds();
        $this->assertFalse($this->inTenant($alpha['org'], fn () => $site->fresh()->online()));
    }
}
