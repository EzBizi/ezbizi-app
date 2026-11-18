<?php

namespace Tests\Feature\Field;

use App\Models\AutomationEvent;
use App\Models\CameraEvent;
use App\Models\TelematicsConnection;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehiclePosition;
use App\Support\Telematics\TelematicsProvider;
use App\Support\Telematics\TelematicsProviders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The provider-agnostic telematics side (docs/telematics-and-dashcams.md). */
class TelematicsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public static int $signs = 0;

    private function run_(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->t['org'], $fn);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        self::$signs = 0;
        TelematicsProviders::$providers['fake'] = FakeTelematics::class;
    }

    protected function tearDown(): void
    {
        unset(TelematicsProviders::$providers['fake']);
        parent::tearDown();
    }

    public function test_connect_checks_lists_devices_and_readings_land_with_the_driver(): void
    {
        $tech = $this->userWithRole($this->t, 'Technician', ['field.view']);
        $p = app(TelematicsProviders::class);

        [$van, $device] = $this->run_(function () use ($p, $tech) {
            $van = Vehicle::query()->create([
                'organization_id' => $this->t['org']->id, 'company_id' => $this->t['company']->id,
                'franchise_id' => $this->t['franchise']->id, 'number' => '101', 'label' => 'Van 101',
                'make' => 'Ford', 'model' => 'Transit', 'status' => 'active', 'body_type' => 'van', 'fuel_type' => 'gas', 'year' => 2024,
            ]);
            VehicleAssignment::query()->create(['organization_id' => $this->t['org']->id, 'vehicle_id' => $van->id, 'user_id' => $tech->id, 'assigned_at' => now()]);
            $c = $p->check(TelematicsConnection::query()->create(['organization_id' => $this->t['org']->id, 'provider' => 'fake', 'label' => 'Fleet', 'credentials' => ['token' => 's3cret']]));
            $this->assertSame('connected', $c->status);
            $device = $c->devices()->sole();
            $device->update(['vehicle_id' => $van->id]);

            $p->ingestPosition($device, ['latitude' => 40.1, 'longitude' => -75.2, 'speed_mph' => 31, 'recorded_at' => '2026-11-16T15:00:00Z']);
            // An older reading joins the trail but does not move the van back.
            $p->ingestPosition($device, ['latitude' => 39.0, 'longitude' => -74.0, 'recorded_at' => '2026-11-16T14:00:00Z']);

            return [$van, $device->fresh()];
        });

        $this->assertSame(40.1, $device->latitude);
        $this->assertSame(2, $this->run_(fn () => VehiclePosition::query()->where('driver_user_id', $tech->id)->count()));
        $this->assertStringNotContainsString('s3cret', (string) $this->run_(fn () => TelematicsConnection::query()->getQuery()->value('credentials')));

        // A camera event: once per external id, emitted for automations, clip re-signed only when stale.
        $event = $this->run_(function () use ($p, $device) {
            $e = $p->ingestCameraEvent($device, ['external_id' => 'e1', 'type' => 'harsh_brake', 'occurred_at' => '2026-11-16T15:01:00Z', 'vendor_media_id' => 'm1']);
            $p->ingestCameraEvent($device, ['external_id' => 'e1', 'type' => 'harsh_brake', 'occurred_at' => '2026-11-16T15:01:00Z']);

            return $e;
        });
        $this->assertSame(1, $this->run_(fn () => CameraEvent::query()->count()));
        $this->assertSame($tech->id, $event->driver_user_id);
        $this->assertSame(1, $this->run_(fn () => AutomationEvent::query()->where('event_key', 'monitoring.camera_event')->count()));

        $this->run_(function () use ($p, $event) {
            $p->freshClipUrl($event);
            $p->freshClipUrl($event);
            $this->assertSame(1, self::$signs, 'A fresh link is reused.');
            $this->travel(2)->hours();
            $this->assertStringContainsString('m1', $p->freshClipUrl($event->fresh())['url']);
            $this->assertSame(2, self::$signs, 'A stale link is signed again.');
        });
    }
}

class FakeTelematics implements TelematicsProvider
{
    public function check(TelematicsConnection $connection): array
    {
        return ['ok' => true, 'detail' => '1 van', 'devices' => [['external_id' => 'v1', 'label' => 'Transit', 'kind' => 'both']]];
    }

    public function clipUrl(TelematicsConnection $connection, CameraEvent $event): array
    {
        TelematicsTest::$signs++;

        return ['url' => "https://vendor.test/{$event->vendor_media_id}?sig=".TelematicsTest::$signs, 'expires_at' => now()->addHour()];
    }
}
