<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Out and back in in under two minutes continues the same shift (Jose,
 * 2026-10-01: "merges, for less than 2 mins shift changes so we don't have
 * these build ups and unnecesary extra rows"). His screenshot: 8:00–8:39 PM,
 * 8:39–8:39 PM, 8:39–8:54 PM Auto, which is one shift.
 */
class TimeClockMergeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_back_in_within_two_minutes_continues_the_shift(): void
    {
        [$alpha, $person] = $this->person();

        $this->at('2026-09-08 13:00:00')->punch($person, 'clock-in');
        $this->at('2026-09-08 20:39:10')->punch($person, 'clock-out');
        $this->at('2026-09-08 20:40:30')->punch($person, 'clock-in');

        $shifts = $this->shifts($alpha, $person);
        $this->assertCount(1, $shifts);
        $this->assertSame('2026-09-08 13:00:00', $shifts[0]->clocked_in_at->toDateTimeString());
        $this->assertNull($shifts[0]->clocked_out_at, 'open again');
        $this->assertNull($shifts[0]->ended_by);
    }

    /** Adeline's case: the clock closed her at the end, she pressed Clock In seconds later. */
    public function test_a_shift_the_clock_ended_continues_with_one_extension(): void
    {
        [$alpha, $person] = $this->person();
        $shift = $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $person->id,
            'clocked_in_at' => '2026-09-08 13:00:00', 'clocked_out_at' => '2026-09-08 22:00:00',
            'scheduled_end_at' => '2026-09-08 22:00:00', 'ended_by' => TimeClockShift::ENDED_BY_SCHEDULE,
        ]));

        $this->at('2026-09-08 22:00:12')->punch($person, 'clock-in');

        $shifts = $this->shifts($alpha, $person);
        $this->assertCount(1, $shifts);
        $this->assertSame($shift->id, $shifts[0]->id);
        $this->assertSame('2026-09-08 22:30:12', $shifts[0]->scheduled_end_at->toDateTimeString(), 'one extension, still an end');
    }

    /** The controls: five minutes is not less than five, and HR's close stays closed. */
    public function test_five_minutes_later_or_after_hr_closed_it_is_a_new_shift(): void
    {
        [$alpha, $person] = $this->person();

        $this->at('2026-09-08 13:00:00')->punch($person, 'clock-in');
        $this->at('2026-09-08 15:00:00')->punch($person, 'clock-out');
        $this->at('2026-09-08 15:05:00')->punch($person, 'clock-in');
        $this->assertCount(2, $this->shifts($alpha, $person));

        $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->whereNull('clocked_out_at')->update([
            'clocked_out_at' => '2026-09-08 16:00:00', 'ended_by' => TimeClockShift::ENDED_BY_MANAGER,
        ]));
        $this->at('2026-09-08 16:00:30')->punch($person, 'clock-in');
        $this->assertCount(3, $this->shifts($alpha, $person));
    }

    /** Jose, 2026-10-13: "if the difference between a clock out and a clock in is less than 5 mins they should merge". */
    public function test_back_in_four_minutes_later_continues_the_shift(): void
    {
        [$alpha, $person] = $this->person();

        $this->at('2026-09-08 13:00:00')->punch($person, 'clock-in');
        $this->at('2026-09-08 15:00:00')->punch($person, 'clock-out');
        $this->at('2026-09-08 15:04:59')->punch($person, 'clock-in');

        $this->assertCount(1, $this->shifts($alpha, $person));
    }

    /**
     * His Tuesday: out at 5:34:38 PM, back in at 5:40:44 PM (over 5 min, so a
     * new shift), then his own edit moved that clock-in to 5:35:00 PM. A
     * correction that leaves a gap under 5 minutes joins the rows; the
     * survivor is still clocked in, and the extensions add up.
     */
    public function test_an_edit_that_closes_the_gap_joins_the_open_shift_to_the_one_before(): void
    {
        [$alpha, $person] = $this->person();
        $hr = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'hr@alpha.test');
        $earlier = $this->shift($alpha, $person, '2026-09-08 13:13:29', '2026-09-08 21:34:38', TimeClockShift::ENDED_BY_SCHEDULE, 6);
        $open = $this->shift($alpha, $person, '2026-09-08 21:40:44', null, null, 3);
        $this->at('2026-09-08 22:00:00');

        $this->signedInAs($hr)->patchJson("/api/v1/hr/time-clock/shifts/{$open->id}", [
            'clocked_in_at' => '2026-09-08T21:35:00Z',
        ])->assertOk();

        $shift = $this->shifts($alpha, $person)->sole();
        $this->assertSame($earlier->id, $shift->id);
        $this->assertSame('2026-09-08 13:13:29', $shift->clocked_in_at->toDateTimeString());
        $this->assertNull($shift->clocked_out_at, 'still clocked in');
        $this->assertSame(9, $shift->extensions);
        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.merged')->count());
    }

    /** His Monday: two closed rows, an edit leaves 1 m 51 s between them, and they become one. */
    public function test_an_edit_that_closes_the_gap_joins_two_closed_shifts(): void
    {
        [$alpha, $person] = $this->person();
        $hr = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'hr@alpha.test');
        $first = $this->shift($alpha, $person, '2026-09-08 11:53:03', '2026-09-08 21:05:02', TimeClockShift::ENDED_BY_PERSON, 1);
        $this->shift($alpha, $person, '2026-09-08 21:12:51', '2026-09-08 21:42:51', TimeClockShift::ENDED_BY_SCHEDULE, 5);
        $this->at('2026-09-08 22:00:00');

        $this->signedInAs($hr)->patchJson("/api/v1/hr/time-clock/shifts/{$first->id}", [
            'clocked_out_at' => '2026-09-08T21:11:00Z',
        ])->assertOk();

        $shift = $this->shifts($alpha, $person)->sole();
        $this->assertSame('2026-09-08 21:42:51', $shift->clocked_out_at->toDateTimeString());
        $this->assertSame(6, $shift->extensions);
    }

    private function shift(array $tenant, User $person, string $in, ?string $out, ?string $endedBy, int $extensions): TimeClockShift
    {
        return $this->inTenant($tenant['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $tenant['org']->id, 'user_id' => $person->id,
            'clocked_in_at' => $in, 'clocked_out_at' => $out, 'ended_by' => $endedBy,
            'scheduled_end_at' => $out ?? '2026-09-08 23:00:00', 'extensions' => $extensions,
        ]));
    }

    /** @return array{0: array, 1: User} */
    private function person(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        foreach (['time_clock.enabled' => true, 'timezone' => 'Etc/GMT+5'] as $key => $value) {
            app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)->set($key, $alpha['org'], $value));
        }
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $this->inTenant($alpha['org'], fn () => $person->forceFill(['work_schedule' => ['days' => [
            'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        ]]])->save());

        return [$alpha, $person->fresh()];
    }

    private function at(string $utc): self
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        return $this;
    }

    private function punch(User $person, string $what): void
    {
        $this->signedInAs($person)->postJson("/api/v1/time-clock/{$what}")->assertOk();
    }

    private function shifts(array $tenant, User $person)
    {
        return $this->inTenant($tenant['org'], fn () => TimeClockShift::query()
            ->where('user_id', $person->id)->orderBy('clocked_in_at')->get());
    }
}
