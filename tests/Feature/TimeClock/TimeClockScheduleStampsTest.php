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
        // The PLACE's clock is what a schedule is read in (2026-09-16). These
        // cases were written when the stored zone drove it, so the place now
        // says what they always meant: a UTC-5 place. Expectations unchanged.
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('timezone', $alpha['org'], 'Etc/GMT+5'));
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

        // The next working day: a re-punch within 5 minutes would continue
        // the first shift rather than stamp a new one (2026-10-13).
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:31:00', 'UTC')); // Wed 07:31 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertFalse($this->open($alpha, $person)->outside_schedule);
    }

    /**
     * RENAMED AND REVERSED 2026-09-23. It used to be called
     * `..._with_no_end_...` and asserted a null `scheduled_end_at` in both
     * branches, which is the defect Jose reported: a null end is a row
     * `CloseEndedShifts` selects past for ever, and five people on production
     * were still clocked in days after they went home.
     *
     * What this pins now is the part that was always right — `today` is null
     * on a day off and `outside_schedule` tells the two branches apart — plus
     * the cap that replaced the null.
     */
    public function test_a_day_off_is_outside_and_after_the_end_is_not_but_both_get_the_cap(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-13T07:00:00Z', $block['scheduled_end_at'], 'sixteen hours on from the punch');
        $this->assertNull($block['today'], 'there is still no window to show');
        $this->assertTrue($this->open($alpha, $person)->outside_schedule);
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:30:00', 'UTC')); // Tuesday 18:30 local, after 17:00
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        // One extension past the punch, not the cap (2026-09-24).
        $this->assertSame('2026-09-09T00:00:00Z', $block['scheduled_end_at']);
        $shift = $this->open($alpha, $person);
        $this->assertFalse($shift->outside_schedule, 'working late is not working outside the schedule');
        $this->assertNotNull($shift->scheduled_end_at);
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

    /**
     * A stored schedule zone is a RECORD of where it was set, not the clock the
     * shift runs on (2026-09-16: Jose's row still said Etc/GMT+5 from before the
     * platform default became America/New_York, so his 17:00 resolved to 18:00
     * Eastern and every reminder and auto clock-out ran an hour late).
     */
    public function test_a_stale_stored_zone_does_not_move_the_scheduled_end(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('timezone', $alpha['org'], 'America/New_York'));

        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $this->inTenant($alpha['org'], fn () => $person->forceFill(['work_schedule' => [
            // A fixed offset, one hour behind Eastern in September.
            'timezone' => 'Etc/GMT+5',
            'days' => self::WEEK,
        ]])->save());

        // Wednesday 2026-09-16, 14:00 UTC = 10:00 Eastern (EDT, UTC-4).
        Carbon::setTestNow(Carbon::parse('2026-09-16 14:00:00', 'UTC'));

        $block = $this->signedInAs($person->fresh())
            ->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');

        // 17:00 Eastern, not the 22:00Z the stored offset would have given.
        $this->assertSame('2026-09-16T21:00:00Z', $block['scheduled_end_at']);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $block['today']);
        $this->assertFalse($this->inTenant($alpha['org'], fn () => TimeClockShift::openFor($person))->outside_schedule);
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }
}
