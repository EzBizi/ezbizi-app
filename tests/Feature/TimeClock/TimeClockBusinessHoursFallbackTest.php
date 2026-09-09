<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * No personal schedule falls back to the Business hours of the person's own
 * place (2026-09-09, Jose: "it did not work for me until I set my own hours").
 */
class TimeClockBusinessHoursFallbackTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_business_hours_lock_and_stamp_a_person_with_no_schedule(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.lock_screen', true);
        $person = $this->userWithRole($alpha, 'Locked', ['time_clock.lock_screen']);
        $this->assertNull($person->work_schedule);

        // Tuesday 10:00 in the default America/New_York (September is EDT,
        // UTC−4); default business hours are Mon-Fri 08:00-17:00. It was 09:00
        // while the default was a fixed UTC−5 — inside the window either way,
        // which is why only the stamp below moved.
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $me = $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertTrue($me['lock']);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $me['today']);

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        // 17:00 New York on an EDT date is 21:00Z. It was 22:00Z while the
        // default was a fixed UTC−5 all year.
        $this->assertSame('2026-09-08T21:00:00Z', $block['scheduled_end_at']);
        $this->assertFalse($this->inTenant($alpha['org'], fn () => TimeClockShift::openFor($person))->outside_schedule);

        // Saturday: business hours are closed, so no lock.
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC'));
        $this->assertFalse($this->signedInAs($person)->getJson('/api/v1/me')->json('data.time_clock.lock'));
    }

    public function test_the_fallback_reads_the_hours_and_clock_of_the_persons_own_franchise(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.lock_screen', true);
        $franchise = $alpha['franchise'];
        $this->set($alpha['org'], 'ops.business_hours', [
            'monday' => ['open' => '09:00', 'close' => '18:00'], 'tuesday' => ['open' => '09:00', 'close' => '18:00'],
            'wednesday' => ['open' => '09:00', 'close' => '18:00'], 'thursday' => ['open' => '09:00', 'close' => '18:00'],
            'friday' => ['open' => '09:00', 'close' => '18:00'], 'saturday' => null, 'sunday' => null,
        ], $franchise);
        $this->set($alpha['org'], 'timezone', 'Etc/GMT+6', $franchise);
        $person = $this->userWithRole($alpha, 'Branch', ['time_clock.lock_screen'], onlyAt: $franchise);

        // Tuesday 10:00 in UTC-6.
        Carbon::setTestNow(Carbon::parse('2026-09-08 16:00:00', 'UTC'));

        $me = $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertTrue($me['lock']);
        $this->assertSame(['start' => '09:00', 'end' => '18:00'], $me['today']);

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-09T00:00:00Z', $block['scheduled_end_at']);
    }

    public function test_a_personal_schedule_still_wins_over_business_hours(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $this->inTenant($alpha['org'], fn () => $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => [
            'monday' => null, 'tuesday' => ['start' => '12:00', 'end' => '20:00'], 'wednesday' => null,
            'thursday' => null, 'friday' => null, 'saturday' => null, 'sunday' => null,
        ]]])->save());
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $me = $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertSame(['start' => '12:00', 'end' => '20:00'], $me['today']);
    }

    private function set(Organization $org, string $key, mixed $value, ?Model $scope = null): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $scope ?? $org, $value));
    }
}
