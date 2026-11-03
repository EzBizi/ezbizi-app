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

/**
 * The shift that ran for a day and a half (Jose, 2026-09-23).
 *
 * He sent a screenshot of Adeline's week: 8:26 AM to 5:00 PM closed and
 * stamped `Auto`, then a second row starting at 5:00 PM and still reading
 * "still clocked in" the following afternoon. Production carried FIVE of
 * those across four days, the oldest from the 14th.
 *
 * The mechanism, read from source and confirmed against the rows: a shift
 * starting at or after the schedule's end for that day was stamped with a null
 * `scheduled_end_at` — deliberately, because somebody working late has nothing
 * to count down to — and `CloseEndedShifts` selects `whereNotNull`. The sweep
 * could not see those rows. Not a race and not a failure: nothing was ever
 * going to close them.
 *
 * Adeline fell into it by eight seconds. The sweep closed her at 21:00:02 UTC
 * and she clocked back in at 21:00:10, past her own end for the day.
 */
class TimeClockNeverEndingShiftTest extends TestCase
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

    /**
     * The guard on the hole itself, stated about the mechanism rather than
     * about one path into it: whatever the schedule says, a shift comes out of
     * clock in WITH an end. A null there is invisible to the sweep, and there
     * is no second thing that closes shifts.
     */
    public function test_clocking_in_after_the_day_has_ended_still_stamps_an_end(): void
    {
        [$alpha, $person] = $this->scheduled();

        // Tuesday 18:00 local — an hour past her own end. This is the row that
        // used to be stamped null and drift for ever.
        Carbon::setTestNow(Carbon::parse('2026-09-08 23:00:00', 'UTC'));
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $shift = $this->open($alpha, $person);

        $this->assertNotNull($shift->scheduled_end_at, 'a null end is a shift nothing will ever close');
        // One extension (30 by default), not the 16-hour cap — see the next test.
        $this->assertSame('2026-09-08 23:30:00', $shift->scheduled_end_at->toDateTimeString());
    }

    /**
     * The lock screen's Clock In, pressed at the end of the day (2026-09-24).
     *
     * The cap closed the forgotten shifts, and the next evening two more
     * opened: the sweep at 21:00:02, Adeline at 21:00:14, Jason at 21:00:24.
     * The lock screen still locks after the scheduled end on a working day and
     * its button is Clock In; somebody who wants their machine back presses it
     * and leaves. Under the cap that was sixteen hours each. It must be one
     * extension, which the reminder warns about and the sweep closes.
     */
    public function test_clocking_back_in_the_moment_the_day_ends_buys_one_extension(): void
    {
        [$alpha, $person] = $this->scheduled();

        // Tuesday 17:00:14 local, twelve seconds after the sweep closed her.
        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00:14', 'UTC'));
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');

        $this->assertSame('2026-09-08T22:30:14Z', $block['scheduled_end_at']);

        // And nobody touches anything: the sweep closes it at the half hour.
        Carbon::setTestNow(Carbon::parse('2026-09-08 22:31:00', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $this->assertNull($this->open($alpha, $person));
        $closed = $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->where('user_id', $person->id)->sole());
        $this->assertSame(30 * 60, $closed->clocked_out_at->getTimestamp() - $closed->clocked_in_at->getTimestamp());
    }

    /** A day off has no schedule at all, and still gets the backstop. */
    public function test_a_day_off_gets_the_cap_too(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $shift = $this->open($alpha, $person);
        $this->assertTrue((bool) $shift->outside_schedule);
        $this->assertSame('2026-09-13 07:00:00', $shift->scheduled_end_at->toDateTimeString());
    }

    /** Inside the schedule the end is the schedule's, not the cap. */
    public function test_a_normal_shift_still_ends_when_the_schedule_says(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC')); // 09:00 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->assertSame('2026-09-08 22:00:00', $this->open($alpha, $person)->scheduled_end_at->toDateTimeString());
    }

    /** The organization decides how long the backstop is. */
    public function test_the_cap_comes_from_the_organization(): void
    {
        [$alpha, $person] = $this->scheduled();
        $this->set($alpha['org'], 'time_clock.max_hours', 8);

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday: no window, so the cap
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->assertSame('2026-09-12 23:00:00', $this->open($alpha, $person)->scheduled_end_at->toDateTimeString());
    }

    /**
     * A late shift can now be EXTENDED, which it could not be before.
     *
     * Not a bonus: extend() refuses a row with no scheduled end, so the person
     * this whole defect is about was also the one person the Extend button did
     * nothing for.
     */
    public function test_a_late_shift_can_be_extended(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:00:00', 'UTC'));
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk();

        $this->assertSame('2026-09-09 00:00:00', $this->open($alpha, $person)->scheduled_end_at->toDateTimeString(), 'two extensions');
    }

    /**
     * End to end, the way it actually failed: punch after hours, let the sweep
     * run, and the row closes. Before 2026-09-23 the sweep ran every minute
     * for nine days over Adeline's row and did nothing to it.
     */
    public function test_the_sweep_closes_a_shift_the_schedule_gave_no_end(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday: no schedule at all
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        // A minute later nothing is due: the cap is a backstop, not a length.
        Carbon::setTestNow(Carbon::parse('2026-09-12 15:01:00', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->assertNotNull($this->open($alpha, $person));

        // Sixteen hours on, plus the minute the scheduler takes to notice.
        Carbon::setTestNow(Carbon::parse('2026-09-13 07:01:00', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $this->assertNull($this->open($alpha, $person), 'still open past the cap');
    }

    /**
     * The same re-punch on a day off (production, 2026-10-25): Romnick's
     * Sunday shift closed at 18:00:01, he clocked back in at 18:00:03, and the
     * reopened shift took the whole cap again and closed at 08:00 Monday —
     * twenty hours. Continuing a shift the clock closed buys one extension.
     */
    public function test_reopening_a_shift_the_clock_closed_buys_one_extension_on_a_day_off(): void
    {
        [$alpha, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday: no schedule
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-09-13 07:00:01', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-09-13 07:00:03', 'UTC'));
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->assertSame('2026-09-13 07:30:03', $this->open($alpha, $person)->scheduled_end_at->toDateTimeString());
    }

    /**
     * A close cannot invent hours.
     *
     * The sweep stamps the END it was sweeping, not the moment it got there.
     * Normally that is one second's difference. On a row the sweep could not
     * see until the backfill handed it an end it is DAYS, and `now()` would
     * have paid Adeline for the 40 hours her forgotten punch sat open.
     */
    public function test_a_scheduled_close_credits_no_time_past_the_end(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $shift = $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $person->id,
            'clocked_in_at' => '2026-09-14 21:58:42',
            'scheduled_end_at' => '2026-09-15 13:58:42',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-09-23 13:00:00', 'UTC')); // eight days late
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $closed = TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());

        $this->assertSame('2026-09-15 13:58:42', $closed->clocked_out_at->toDateTimeString());
        $this->assertSame(
            16 * 3600,
            $closed->clocked_out_at->getTimestamp() - $closed->clocked_in_at->getTimestamp(),
            'the cap, not the eight days it drifted'
        );
    }

    /**
     * The migration, run as a migration rather than reimplemented here — the
     * FILE is required and `up()` called, so editing it is what this measures.
     */
    public function test_the_backfill_hands_the_stranded_rows_an_end(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $stranded = $this->userWithRole($alpha, 'A', ['time_clock.view'], 'a@alpha.test');
        $working = $this->userWithRole($alpha, 'B', ['time_clock.view'], 'b@alpha.test');

        $strandedRow = $this->row($alpha['org'], $stranded, '2026-09-22 21:00:10', null);
        $scheduledRow = $this->row($alpha['org'], $working, '2026-09-23 12:04:01', '2026-09-23 21:00:00');

        $migration = require database_path('migrations/2026_09_23_100000_give_every_open_shift_an_end.php');
        $migration->up();

        $this->assertSame('2026-09-23 13:00:10', $this->fresh($strandedRow)->scheduled_end_at->toDateTimeString());

        // Somebody mid-shift with a real end keeps it.
        $this->assertSame('2026-09-23 21:00:00', $this->fresh($scheduledRow)->scheduled_end_at->toDateTimeString());
    }

    /** @return array{0: array, 1: User} */
    private function scheduled(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        // A UTC-5 place, as the other schedule suites use: it keeps the
        // expectations above arithmetic rather than a DST table.
        $this->set($alpha['org'], 'timezone', 'Etc/GMT+5');

        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $person = $this->inTenant($alpha['org'], function () use ($person) {
            $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();

            return $person->fresh();
        });

        return [$alpha, $person];
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }

    private function row(Organization $org, User $user, string $in, ?string $end): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => $in,
            'scheduled_end_at' => $end,
        ]));
    }

    private function fresh(TimeClockShift $shift): TimeClockShift
    {
        return TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());
    }

    private function open(array $tenant, User $user): ?TimeClockShift
    {
        return $this->inTenant($tenant['org'], fn () => TimeClockShift::query()
            ->whereNull('clocked_out_at')
            ->where('user_id', $user->getKey())
            ->first());
    }
}
