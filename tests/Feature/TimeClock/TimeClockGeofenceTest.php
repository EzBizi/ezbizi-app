<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The clock-in zone, taken over from A1 Tools (2026-10-16). The centre is
 * Norwalk, CT; a point 60 m north is inside a 100 m zone, 1 km north is not.
 */
class TimeClockGeofenceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CENTER = '41.1177, -73.4082';

    private const NEAR = ['latitude' => 41.11824, 'longitude' => -73.4082, 'accuracy' => 10];

    private const FAR = ['latitude' => 41.1267, 'longitude' => -73.4082, 'accuracy' => 10];

    public function test_a_tech_clocks_in_inside_the_zone_and_is_refused_outside_or_without_a_location(): void
    {
        [$alpha, $tech] = $this->tenant(zoneOn: true);

        $this->assertTrue($this->signedInAs($tech)->getJson('/api/v1/time-clock/me')->json('data.time_clock.needs_location'));

        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in', [])
            ->assertStatus(422)->assertJsonPath('errors.location.0', fn ($m) => str_contains($m, 'location is needed'));
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in', self::FAR)
            ->assertStatus(422)->assertJsonPath('errors.location.0', fn ($m) => str_contains($m, 'm away (allowed 100 m)'));
        $this->assertSame(0, $this->shifts($tech));
        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.geofence_blocked')->count());

        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in', self::NEAR)->assertOk();
        $this->assertSame(1, $this->shifts($tech));

        // Leaving by hand is checked too.
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-out', self::FAR)->assertStatus(422);
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-out', self::NEAR)->assertOk();
    }

    /** The controls: a role that may clock in from anywhere, and a place with the zone off. */
    public function test_an_exempt_role_and_a_place_with_the_zone_off_are_never_asked(): void
    {
        [$alpha, $tech] = $this->tenant(zoneOn: true);
        $office = $this->userWithRole($alpha, 'Dispatcher', ['time_clock.view', 'time_clock.anywhere'], 'office@alpha.test');

        $this->assertFalse($this->signedInAs($office)->getJson('/api/v1/time-clock/me')->json('data.time_clock.needs_location'));
        $this->signedInAs($office)->postJson('/api/v1/time-clock/clock-in', [])->assertOk();

        [, $elsewhere] = $this->tenant(zoneOn: false, name: 'Beta');
        $this->assertFalse($this->signedInAs($elsewhere)->getJson('/api/v1/time-clock/me')->json('data.time_clock.needs_location'));
        $this->signedInAs($elsewhere)->postJson('/api/v1/time-clock/clock-in', [])->assertOk();
    }

    /** The end-of-day close never carries a device and must always close. */
    public function test_the_automatic_close_ignores_the_zone(): void
    {
        [$alpha, $tech] = $this->tenant(zoneOn: true);
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in', self::NEAR)->assertOk();

        $this->inTenant($alpha['org'], fn () => app(TimeClock::class)->clockOut($tech, endedBy: TimeClockShift::ENDED_BY_SCHEDULE));

        $this->assertSame(0, TimeClockShift::query()->withoutGlobalScopes()->whereNull('clocked_out_at')->count());
    }

    /** @return array{0: array, 1: User} */
    private function tenant(bool $zoneOn, string $name = 'Alpha'): array
    {
        $t = $this->provisionTenant($name);
        $set = fn (string $key, mixed $value) => app(TenantContext::class)->run($t['org'], fn () => app(SettingsResolver::class)->set($key, $t['org'], $value));
        $set('time_clock.enabled', true);
        $set('time_clock.geofence_enabled', $zoneOn);
        $set('time_clock.geofence_center', self::CENTER);

        return [$t, $this->userWithRole($t, 'Tech', ['time_clock.view'], 'tech@'.strtolower($name).'.test')];
    }

    private function shifts(User $user): int
    {
        return TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $user->id)->count();
    }
}
