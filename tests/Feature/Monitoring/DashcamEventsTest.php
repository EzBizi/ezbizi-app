<?php

namespace Tests\Feature\Monitoring;

use App\Models\AuditEvent;
use App\Models\CameraEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\TelematicsConnection;
use App\Models\Vehicle;
use App\Support\Telematics\TelematicsProvider;
use App\Support\Telematics\TelematicsProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Dash cam events in Monitoring (Jose 11-16): reach through the van, the clip stays with the vendor. */
class DashcamEventsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TelematicsProviders::$providers['dashfake'] = DashFake::class;
    }

    protected function tearDown(): void
    {
        unset(TelematicsProviders::$providers['dashfake']);
        parent::tearDown();
    }

    public function test_events_follow_the_vans_reach_and_a_clip_is_a_vendor_link_that_is_audited(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$mine, $theirs, $orphan] = $this->inTenant($t['org'], function () use ($t) {
            $companyB = Company::create(['organization_id' => $t['org']->id, 'name' => 'B', 'is_active' => true]);
            $franchiseB = Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $companyB->id, 'name' => 'B1', 'is_active' => true]);
            $van = fn ($c, $f, $n) => Vehicle::query()->create([
                'organization_id' => $t['org']->id, 'company_id' => $c, 'franchise_id' => $f, 'number' => $n, 'label' => "Van $n",
                'make' => 'Ford', 'model' => 'Transit', 'status' => 'active', 'body_type' => 'van', 'fuel_type' => 'gas', 'year' => 2024,
            ]);
            $vanA = $van($t['company']->id, $t['franchise']->id, '101');
            $vanB = $van($companyB->id, $franchiseB->id, '201');
            $conn = TelematicsConnection::query()->create(['organization_id' => $t['org']->id, 'provider' => 'dashfake', 'label' => 'Cams', 'credentials' => ['token' => 'x']]);
            $device = $conn->devices()->create(['organization_id' => $t['org']->id, 'external_id' => 'd1', 'label' => 'Cam', 'kind' => 'camera']);
            $event = fn ($vehicle, $id, $type) => CameraEvent::query()->create([
                'organization_id' => $t['org']->id, 'device_id' => $device->id, 'vehicle_id' => $vehicle?->id,
                'external_id' => $id, 'type' => $type, 'occurred_at' => now(), 'vendor_media_id' => "m-$id",
            ]);

            return [$event($vanA, 'a', 'harsh_brake'), $event($vanB, 'b', 'collision'), $event(null, 'c', 'speeding')];
        });

        $viewer = $this->userWithRole($t, 'Viewer', ['monitoring.view', 'monitoring.cameras.view'], onlyAt: $t['franchise']);

        $ids = array_column($this->signedInAs($viewer)->getJson('/api/v1/monitoring/dashcam-events')->assertOk()->json('data'), 'id');
        $this->assertSame([$mine->id], $ids, 'own van listed; sibling company and van-less events are not');

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/dashcam-events?type=collision')->assertOk()->assertJsonCount(0, 'data');

        $clip = $this->signedInAs($viewer)->postJson("/api/v1/monitoring/dashcam-events/{$mine->id}/clip-url")->assertOk()->json('data');
        $this->assertSame('https://vendor.test/m-a?sig=1', $clip['url']);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('action', 'monitoring.dashcam.clip_viewed')->count());

        $this->signedInAs($viewer)->postJson("/api/v1/monitoring/dashcam-events/{$theirs->id}/clip-url")->assertNotFound();

        $none = $this->userWithRole($t, 'None', ['monitoring.view']);
        $this->signedInAs($none)->getJson('/api/v1/monitoring/dashcam-events')->assertForbidden();
    }
}

class DashFake implements TelematicsProvider
{
    public static int $signs = 0;

    public function check(TelematicsConnection $connection): array
    {
        return ['ok' => true, 'detail' => '', 'devices' => []];
    }

    public function clipUrl(TelematicsConnection $connection, CameraEvent $event): array
    {
        self::$signs++;

        return ['url' => "https://vendor.test/{$event->vendor_media_id}?sig=".self::$signs, 'expires_at' => now()->addHour()];
    }
}
