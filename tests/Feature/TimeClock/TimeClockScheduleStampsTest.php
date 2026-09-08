<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockScheduleStampsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'],
        'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'],
        'saturday' => null,
        'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: User} */
    private function scheduled(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $person = $this->inTenant($alpha['org'], function () use ($person) {
            $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();

            return $person->fresh();
        });

        return [$alpha, $person];
    }

    public function test_clock_in_on_a_working_day_stamps_the_end_in_the_schedule_zone(): void
    {
        [$alpha, $person] = $this->scheduled();
        // Tuesday 2026-09-08 09:00 in UTC-5 = 14:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');

        $this->assertSame('2026-09-08T22:00:00Z', $block['scheduled_end_at']);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $block['today']);
        $this->assertSame(5, $block['warning_minutes']);
        $this->assertSame(30, $block['extension_minutes']);
        $this->assertFalse($block['lock']);

        $shift = $this->open($alpha, $person);
        $this->assertFalse($shift->outside_schedule);
        $this->assertSame('2026-09-08 22:00:00', $shift->scheduled_end_at->toDateTimeString());
    }

    public function test_thirty_one_minutes_early_is_outside_and_twenty_nine_is_not(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:29:00', 'UTC')); // 07:29 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertTrue($this->open($alpha, $person)->outside_schedule);
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:31:00', 'UTC')); // 07:31 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertFalse($this->open($alpha, $person)->outside_schedule);
    }

    public function test_a_day_off_is_outside_with_no_end_and_after_the_end_is_neither(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertNull($block['scheduled_end_at']);
        $this->assertNull($block['today']);
        $this->assertTrue($this->open($alpha, $person)->outside_schedule);
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:30:00', 'UTC')); // Tuesday 18:30 local, after 17:00
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertNull($block['scheduled_end_at']);
        $shift = $this->open($alpha, $person);
        $this->assertFalse($shift->outside_schedule);
        $this->assertNull($shift->scheduled_end_at);
    }

    public function test_a_person_closes_with_ended_by_person(): void
    {
        [$alpha, $person] = $this->scheduled();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $closed = TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $person->id)->first();
        $this->assertSame(TimeClockShift::ENDED_BY_PERSON, $closed->ended_by);
    }

    public function test_time_clock_me_returns_the_same_block_as_me(): void
    {
        [$alpha, $person] = $this->scheduled();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $viaMe = $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $direct = $this->signedInAs($person)->getJson('/api/v1/time-clock/me')->assertOk()->json('data.time_clock');

        $this->assertSame($viaMe, $direct);
        $this->assertArrayHasKey('lock', $direct);
    }

    private function open(array $alpha, User $person): ?TimeClockShift
    {
        return $this->inTenant($alpha['org'], fn () => TimeClockShift::openFor($person));
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }
}
