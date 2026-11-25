<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Models\User;
use App\Support\Monitoring\ScreenshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `GET /api/v1/monitoring/screenshots` and `.../{id}` — the read side
 * (2026-08-31-monitoring-screenshots, Task 4).
 *
 * The load-bearing test is the last one, mirroring
 * DevicesReadTest::test_a_company_view_does_not_list_a_sibling_companys_devices:
 * reach here is scoped by the screenshot's DEVICE's OWNER, via
 * `ScopeReach::constrainToVisiblePeople` through `device.user`
 * (ScreenshotsController::visibleTo) — not by any place column on the row
 * itself — and it has to hold for BOTH the list and a direct `{id}` fetch,
 * or a guessed/shared id would leak a sibling company's screen captures
 * that the list already correctly hides.
 */
class ScreenshotReadTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /**
     * A device + screenshot row owned by $owner, with a REAL file behind it.
     *
     * A fake/missing path would also 404 `show` — for a reason that has
     * nothing to do with reach — and make a reach assertion pass whether or
     * not the reach filter is even there. Writing real bytes through the
     * real store keeps a 404 here attributable to reach alone.
     */
    private function makeShot(array $tenant, User $owner): MonitoringScreenshot
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $owner) {
            $device = MonitoredDevice::factory()->create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $owner->getKey(),
            ]);

            $bytes = random_bytes(16);
            $path = app(ScreenshotStore::class)->put(
                $tenant['org']->id, $device->id, now()->toIso8601String().uniqid(), $bytes,
            );

            return MonitoringScreenshot::factory()->create([
                'organization_id' => $tenant['org']->id,
                'device_id' => $device->id,
                'user_id' => $owner->getKey(),
                'path' => $path,
                'bytes' => strlen($bytes),
            ]);
        });
    }

    public function test_no_screenshots_view_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/screenshots')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_no_screenshots_view_permission_refuses_show_too(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $shot = $this->makeShot($alpha, $alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/screenshots/'.$shot->id)
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_metadata_list_and_jpeg_bytes_are_returned_with_the_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        // A REAL file on disk, through the real store, so `show` proves it
        // streams actual bytes rather than merely returning 200.
        $jpegBytes = str_repeat('x', 128);
        $shot = $this->inTenant($alpha['org'], function () use ($alpha, $jpegBytes) {
            $device = MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $alpha['admin']->getKey(),
            ]);

            $path = app(ScreenshotStore::class)->put(
                $alpha['org']->id, $device->id, now()->toIso8601String(), $jpegBytes,
            );

            return MonitoringScreenshot::factory()->create([
                'organization_id' => $alpha['org']->id,
                'device_id' => $device->id,
                'user_id' => $alpha['admin']->getKey(),
                'path' => $path,
                'bytes' => strlen($jpegBytes),
                'width' => 111,
                'height' => 222,
            ]);
        });

        $row = $this->signedInAs($viewer)->getJson('/api/v1/monitoring/screenshots')
            ->assertOk()->json('data.0');

        $this->assertSame($shot->id, $row['id']);
        $this->assertSame($shot->device_id, $row['device_id']);
        $this->assertSame(strlen($jpegBytes), $row['bytes']);
        $this->assertSame(111, $row['width']);
        $this->assertSame(222, $row['height']);

        $resp = $this->signedInAs($viewer)->get('/api/v1/monitoring/screenshots/'.$shot->id);
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($jpegBytes, $resp->getContent());
    }

    public function test_one_day_pages_back_through_every_capture_with_its_counts(): void
    {
        // Jose 11-25: the filmstrip showed only the newest 12 of ~5,000.
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);
        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
        ]));
        $day = now()->startOfDay()->addHours(12);
        $shot = fn ($at) => $this->inTenant($alpha['org'], fn () => MonitoringScreenshot::factory()->create([
            'organization_id' => $alpha['org']->id, 'device_id' => $device->id,
            'user_id' => $alpha['admin']->getKey(), 'captured_at' => $at,
        ]))->id;
        $inDay = [$shot($day->copy()->addMinutes(3)), $shot($day->copy()->addMinutes(2)), $shot($day->copy()->addMinute())];
        $yesterday = $shot($day->copy()->subDay());

        $q = fn (array $p) => '/api/v1/monitoring/screenshots?'.http_build_query(['device_id' => $device->id] + $p);
        $from = $day->copy()->startOfDay()->toIso8601String();
        $to = $day->copy()->endOfDay()->toIso8601String();

        $page1 = $this->signedInAs($viewer)->getJson($q(['from' => $from, 'to' => $to, 'limit' => 2]))->assertOk();
        $this->assertSame(array_slice($inDay, 0, 2), $page1->json('data.*.id'));
        $this->assertSame(['in_range' => 3, 'total' => 4], $page1->json('meta'));

        $page2 = $this->signedInAs($viewer)->getJson($q(['from' => $from, 'to' => $to, 'limit' => 2,
            'before' => $page1->json('data.1.captured_at')]))->assertOk();
        $this->assertSame([$inDay[2]], $page2->json('data.*.id'), 'the rest of the day, and not yesterday');
        $this->assertNotContains($yesterday, $page2->json('data.*.id'));
    }

    public function test_filtering_by_device_id_and_a_before_cursor(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        [$deviceA, $deviceB] = $this->inTenant($alpha['org'], fn () => [
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            ]),
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            ]),
        ]);

        $older = $this->inTenant($alpha['org'], fn () => MonitoringScreenshot::factory()->create([
            'organization_id' => $alpha['org']->id, 'device_id' => $deviceA->id,
            'user_id' => $alpha['admin']->getKey(), 'captured_at' => now()->subMinutes(10),
        ]));
        $newer = $this->inTenant($alpha['org'], fn () => MonitoringScreenshot::factory()->create([
            'organization_id' => $alpha['org']->id, 'device_id' => $deviceA->id,
            'user_id' => $alpha['admin']->getKey(), 'captured_at' => now(),
        ]));
        $otherDevice = $this->inTenant($alpha['org'], fn () => MonitoringScreenshot::factory()->create([
            'organization_id' => $alpha['org']->id, 'device_id' => $deviceB->id,
            'user_id' => $alpha['admin']->getKey(), 'captured_at' => now(),
        ]));

        $ids = $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/screenshots?device_id='.$deviceA->id)
            ->assertOk()->json('data.*.id');

        $this->assertContains($older->id, $ids);
        $this->assertContains($newer->id, $ids);
        $this->assertNotContains($otherDevice->id, $ids);

        // Newest first.
        $this->assertSame($newer->id, $ids[0]);

        $before = $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/screenshots?device_id='.$deviceA->id
                .'&before='.$newer->captured_at->toIso8601String())
            ->assertOk()->json('data.*.id');

        $this->assertContains($older->id, $before);
        $this->assertNotContains($newer->id, $before);
    }

    /**
     * §3: two companies under one org; a viewer scoped to company A's own
     * franchise must not see a device — or its screenshots — owned by
     * someone assigned under sibling company B. Setup mirrors
     * DevicesReadTest::test_a_company_view_does_not_list_a_sibling_companys_devices.
     */
    public function test_a_company_view_does_not_see_a_sibling_companys_screenshots(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });

        // Somebody assigned under the SIBLING company, with a device and a capture.
        $theirs = $this->userWithRole($alpha, 'Distant', [], onlyAt: $elsewhere);
        $theirShot = $this->makeShot($alpha, $theirs);

        // A viewer scoped to company A's own franchise only, plus a capture of their own.
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['monitoring.screenshots.view'], onlyAt: $alpha['franchise'],
        );
        $mineShot = $this->makeShot($alpha, $manager);

        $ids = $this->signedInAs($manager)->getJson('/api/v1/monitoring/screenshots')
            ->assertOk()->json('data.*.id');

        $this->assertContains($mineShot->id, $ids);
        $this->assertNotContains($theirShot->id, $ids);

        // Not just absent from the list — unreachable by a direct id either.
        $this->signedInAs($manager)->getJson('/api/v1/monitoring/screenshots/'.$theirShot->id)
            ->assertNotFound();

        $this->signedInAs($manager)->getJson('/api/v1/monitoring/screenshots/'.$mineShot->id)
            ->assertOk();
    }
}
