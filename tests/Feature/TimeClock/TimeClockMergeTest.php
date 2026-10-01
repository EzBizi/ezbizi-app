<?php

namespace Tests\Feature\TimeClock;

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

    /** The controls: two minutes is not less than two, and HR's close stays closed. */
    public function test_two_minutes_later_or_after_hr_closed_it_is_a_new_shift(): void
    {
        [$alpha, $person] = $this->person();

        $this->at('2026-09-08 13:00:00')->punch($person, 'clock-in');
        $this->at('2026-09-08 15:00:00')->punch($person, 'clock-out');
        $this->at('2026-09-08 15:02:00')->punch($person, 'clock-in');
        $this->assertCount(2, $this->shifts($alpha, $person));

        $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->whereNull('clocked_out_at')->update([
            'clocked_out_at' => '2026-09-08 16:00:00', 'ended_by' => TimeClockShift::ENDED_BY_MANAGER,
        ]));
        $this->at('2026-09-08 16:00:30')->punch($person, 'clock-in');
        $this->assertCount(3, $this->shifts($alpha, $person));
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
