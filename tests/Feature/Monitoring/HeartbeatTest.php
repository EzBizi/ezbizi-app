<?php

namespace Tests\Feature\Monitoring;

use App\Models\AutomationEvent;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat(array $tenant, $user, array $body)
    {
        return $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', $body);
    }

    public function test_first_beat_creates_one_device_and_raises_new_device_seen(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web',
            'capabilities' => ['activity'], 'current_module' => 'jobs',
        ])->assertNoContent();

        // withoutGlobalScopes(): SetTenantContext clears the tenant once the
        // request has finished (its `finally`), so a read out here — after the
        // HTTP round trip, back in the test's own context — is exactly the
        // cross-tenant-shaped read TenantScope wants named explicitly. See
        // BelongsToTenant::class for the two named ways to say so.
        $this->assertSame(1, MonitoredDevice::withoutGlobalScopes()->count());
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'monitoring.new_device_seen')->count());
    }

    public function test_second_beat_updates_in_place_and_raises_no_further_event(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $body = ['device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity']];
        $this->beat($alpha, $alpha['admin'], $body);
        $this->beat($alpha, $alpha['admin'], $body + ['current_module' => 'customers']);

        $this->assertSame(1, MonitoredDevice::withoutGlobalScopes()->count());
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'monitoring.new_device_seen')->count());
    }

    public function test_a_segment_appends_exactly_one_activity_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            'current_module' => 'customers',
            'segment' => ['module_key' => 'jobs', 'dwell_seconds' => 1320],
        ]);
        $this->assertSame(1, MonitoringActivity::withoutGlobalScopes()->count());
        $this->assertSame(1320, (int) MonitoringActivity::withoutGlobalScopes()->value('dwell_seconds'));
    }

    public function test_specs_are_ignored_unless_the_device_reports_the_specs_capability(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            'specs' => ['ram_gb' => 16],
        ]);
        $this->assertNull(MonitoredDevice::withoutGlobalScopes()->value('specs'));
    }

    public function test_a_heartbeat_does_not_slide_the_idle_window(): void
    {
        // The whole point of putting the route outside `idle`: a 60s beat must
        // not keep a walked-away session alive forever. Deliberately NOT using
        // signedInAs()/Sanctum::actingAs() here: SlideIdleWindow::isSlidable()
        // requires a real PersonalAccessToken with a stored created_at, and
        // actingAs() installs a mock that answers false to every attribute it
        // was not told about — which would make this test pass whether or not
        // the route sits inside `idle`. A real bearer token round trip is the
        // only way this can actually fail.
        $alpha = $this->provisionTenant('Alpha');
        $token = $alpha['admin']->createToken('t');
        $token->accessToken->forceFill(['expires_at' => now()->addMinutes(30)])->save();
        $before = $token->accessToken->fresh()->expires_at;

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->postJson('/api/v1/monitoring/heartbeat', [
                'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            ])->assertNoContent();

        $this->assertEquals(
            $before->timestamp,
            $token->accessToken->fresh()->expires_at->timestamp,
            'A heartbeat slid the idle window — it must sit outside the `idle` middleware.'
        );
    }
}
