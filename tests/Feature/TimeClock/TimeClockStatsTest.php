<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Work Hours › Dashboard (Jose, 2026-10-01): "how many clock in on time, or
 * outside schedule, etc". Thursday 2026-10-01, 14:00 New York; both people
 * work Mon–Fri 08:00–16:00, grace 5 minutes.
 */
class TimeClockStatsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_day_is_judged_by_its_first_in_and_last_out_against_the_schedule(): void
    {
        [$alpha, $viewer, $ana, $ben] = $this->tenant();

        // Mon: 08:02 (on time, inside grace) to 16:00, flagged outside schedule.
        $this->shift($alpha['org'], $ana, '2026-09-28 12:02', '2026-09-28 20:00', ['outside_schedule' => true]);
        // Tue: 08:20 (20 min late), lunch, then out at 15:00 (left early).
        $this->shift($alpha['org'], $ana, '2026-09-29 12:20', '2026-09-29 16:00');
        $this->shift($alpha['org'], $ana, '2026-09-29 16:30', '2026-09-29 19:00');
        // Wed: nothing, nothing said: missed.
        // Thu: 07:59, still in. Today cannot be missed and is not early.
        $this->shift($alpha['org'], $ana, '2026-10-01 11:59', null);

        // Ben: sick on Wednesday, nothing else. Mon and Tue are missed.
        $this->inTenant($alpha['org'], fn () => TimeClockDay::query()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $ben->id,
            'date' => '2026-09-30', 'tag' => 'sick', 'extra_minutes' => 0,
        ]));

        $data = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock/stats?from=2026-09-28&to=2026-10-04')
            ->assertOk()->json('data');

        $row = collect($data['people'])->firstWhere('id', $ana->id);
        $this->assertSame(4, $row['scheduled_days'], 'Fri is still to come');
        $this->assertSame(2, $row['on_time']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(20, $row['late_average_minutes']);
        $this->assertSame(1, $row['left_early']);
        $this->assertSame(1, $row['missed']);
        $this->assertSame(1, $row['outside_schedule']);
        $this->assertSame(3, $row['days_worked']);
        $this->assertSame(67, $row['on_time_rate']);
        // (482 + 500 + 479) / 3 = 487 minutes past midnight.
        $this->assertSame('8:07 AM', $row['average_in']);
        $this->assertArrayNotHasKey('_in_minutes', $row);

        $bens = collect($data['people'])->firstWhere('id', $ben->id);
        $this->assertSame(2, $bens['missed'], 'the sick day is not missed');
        $this->assertSame(1, $bens['tags']['sick']);
        $this->assertNull($bens['on_time_rate'], 'no days to judge, not 0%');

        $this->assertSame(3, $data['team']['missed']);
        $this->assertSame(67, $data['team']['on_time_rate']);
        $this->assertSame('2026-09-28', $data['period']['from']);
    }

    /**
     * `?user=`: one person and their days, for the Dashboard's panel (UI/UX,
     * 2026-10-01). Usual times count scheduled days only: the Sunday call-in
     * to 6:30 PM would put the usual clock-out at 4:30 PM instead of 3:30.
     */
    public function test_one_person_carries_their_days_and_usual_times_skip_unscheduled_days(): void
    {
        [$alpha, $viewer, $ana] = $this->tenant();
        $this->shift($alpha['org'], $ana, '2026-09-27 14:00', '2026-09-27 22:30'); // Sunday 10:00-18:30
        $this->shift($alpha['org'], $ana, '2026-09-28 12:02', '2026-09-28 20:00');
        $this->shift($alpha['org'], $ana, '2026-09-29 12:20', '2026-09-29 16:00');
        $this->shift($alpha['org'], $ana, '2026-09-29 16:30', '2026-09-29 19:00');

        $row = $this->signedInAs($viewer)
            ->getJson("/api/v1/hr/time-clock/stats?from=2026-09-27&to=2026-10-01&user={$ana->id}")
            ->assertOk()->assertJsonCount(1, 'data.people')->json('data.people.0');

        $this->assertSame('3:30 PM', $row['average_out']);
        $this->assertSame(['2026-10-01', '2026-09-30', '2026-09-29', '2026-09-28', '2026-09-27'], array_column($row['days'], 'date'));

        $tuesday = $row['days'][2];
        $this->assertSame(['start' => '8:00 AM', 'end' => '4:00 PM'], $tuesday['scheduled']);
        $this->assertSame('8:20 AM', $tuesday['first_in']);
        $this->assertSame('3:00 PM', $tuesday['last_out']);
        $this->assertSame(20, $tuesday['late_minutes']);
        $this->assertSame(60, $tuesday['left_early_minutes']);
        $this->assertSame(2, $tuesday['shifts']);

        $this->assertTrue($row['days'][1]['missed'], 'Wednesday');
        $this->assertNull($row['days'][4]['scheduled'], 'Sunday is not scheduled');
        $this->assertFalse($row['days'][0]['missed'], 'today is not over');

        $elsewhere = $this->provisionTenant('Beta');
        $stranger = $this->userWithRole($elsewhere, 'Tech', ['time_clock.view'], 'x@beta.test');
        $this->signedInAs($viewer)->getJson("/api/v1/hr/time-clock/stats?user={$stranger->id}")->assertNotFound();
    }

    public function test_the_numbers_need_the_tab_and_refuse_a_period_too_long(): void
    {
        [$alpha, $viewer] = $this->tenant();
        $other = $this->userWithRole($alpha, 'Reports only', ['work_hours.view', 'work_hours.reports.view'], 'rep@alpha.test');

        $this->signedInAs($other)->getJson('/api/v1/hr/time-clock/stats')->assertForbidden();
        $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/stats')->assertOk()
            ->assertJsonPath('data.period.from', '2026-09-27'); // this (Sunday) week
        $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/stats?from=2026-01-01&to=2026-10-01')
            ->assertStatus(422);
    }

    /** @return array{0: array, 1: User, 2: User, 3: User} */
    private function tenant(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00:00', 'UTC'));
        $alpha = $this->provisionTenant('Alpha');
        foreach (['time_clock.enabled' => true, 'timezone' => 'America/New_York', 'time_format' => '12h'] as $key => $value) {
            $this->set($alpha['org'], $key, $value);
        }

        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.view', 'work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $week = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['start' => '08:00', 'end' => '16:00']);
        $people = [];
        foreach (['ana', 'ben'] as $name) {
            $people[] = $person = $this->userWithRole($alpha, "Tech {$name}", ['time_clock.view'], "{$name}@alpha.test");
            $this->inTenant($alpha['org'], fn () => $person->forceFill(['work_schedule' => ['days' => $week]])->save());
        }

        return [$alpha, $viewer, ...$people];
    }

    private function shift(Organization $org, User $user, string $in, ?string $out, array $extra = []): void
    {
        $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
            'ended_by' => $out === null ? null : TimeClockShift::ENDED_BY_PERSON,
            ...$extra,
        ]));
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
