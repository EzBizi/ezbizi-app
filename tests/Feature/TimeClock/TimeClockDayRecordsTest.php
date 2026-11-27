<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
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
 * Force clock out, extra hours, notes and day tags (Jose, 2026-09-24):
 * "i don't see the buttons to edit - delete - force clock out - extra hours -
 * notes - i also want to be able to add tags like Office - Home - Absent -
 * Sick - Holiday - Vacation".
 *
 * And the reach rule under all of it: the grant says a person may correct
 * hours, not WHOSE. Another franchise's people are refused as not found.
 */
class TimeClockDayRecordsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 2026-09-24, 14:00 in New York.
        Carbon::setTestNow(Carbon::parse('2026-09-24 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Force clock out ──────────────────────────────────────────

    public function test_force_clock_out_ends_the_shift_now_and_says_who(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->shift($alpha['org'], $tech, '2026-09-24 13:00:00', null);

        $this->signedInAs($manager)
            ->postJson("/api/v1/hr/time-clock/shifts/{$shift->id}/clock-out")
            ->assertOk();

        $fresh = $this->fresh($shift);
        $this->assertSame('2026-09-24 18:00:00', $fresh->clocked_out_at->toDateTimeString(), 'the server clock, not the client');
        $this->assertSame(TimeClockShift::ENDED_BY_MANAGER, $fresh->ended_by);

        // It IS a clock-out that happened, so a rule on clocked_out fires.
        $this->assertSame(1, AutomationEvent::query()->withoutGlobalScopes()->where('event_key', 'time_clock.clocked_out')->count());
    }

    public function test_force_clock_out_refuses_a_finished_shift(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->shift($alpha['org'], $tech, '2026-09-23 13:00:00', '2026-09-23 21:00:00');

        $this->signedInAs($manager)
            ->postJson("/api/v1/hr/time-clock/shifts/{$shift->id}/clock-out")
            ->assertStatus(422)
            ->assertJsonPath('errors.shift.0', 'This shift has already ended.');
    }

    // ── Day records ──────────────────────────────────────────────

    public function test_a_tag_a_note_and_extra_hours_land_on_the_day_and_in_the_week(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $this->shift($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00'); // 8h Tuesday

        $week = $this->signedInAs($manager)
            ->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22", [
                'tag' => 'home',
                'extra_minutes' => 90,
                'note' => 'Stayed on for the Henderson call.',
            ])
            ->assertOk()
            ->json('data');

        $person = collect($week['people'])->firstWhere('id', $tech->id);

        $this->assertSame([[
            'date' => '2026-09-22',
            'tag' => 'home',
            'extra_minutes' => 90,
            'note' => 'Stayed on for the Henderson call.',
        ]], $person['days']);

        // Eight hours clocked plus ninety minutes nobody's clock saw.
        $this->assertSame(8 * 3600 + 90 * 60, $person['total_seconds']);
        $this->assertSame(1, $person['days_worked']);

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.day_recorded')->count());
    }

    /** Extra hours on a day with no shift make it a day worked; a tag alone does not. */
    public function test_extra_hours_make_a_day_worked_and_sick_does_not(): void
    {
        [, $manager, $tech] = $this->tenant();

        $this->signedInAs($manager)->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-21", ['tag' => 'sick'])->assertOk();

        $person = collect(
            $this->signedInAs($manager)
                ->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22", ['extra_minutes' => 120])
                ->assertOk()
                ->json('data.people')
        )->firstWhere('id', $tech->id);

        $this->assertSame(1, $person['days_worked'], 'the sick day is not a day worked');
        $this->assertSame(120 * 60, $person['average_seconds'], 'and does not drag the average down');
    }

    /** Day tags are the organization's setting (Jose, 2026-11-04): a new one is accepted, a removed one refused. */
    public function test_day_tags_come_from_the_setting(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('time_clock.day_tags', $alpha['org'], [
            ['value' => 'office', 'label' => 'Office'], ['value' => 'training', 'label' => 'Training'],
        ]));
        $url = "/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22";

        $this->signedInAs($manager)->putJson($url, ['tag' => 'training'])->assertOk();
        $this->signedInAs($manager)->putJson($url, ['tag' => 'sick'])->assertUnprocessable();

        $this->assertSame(
            [['value' => 'office', 'label' => null], ['value' => 'training', 'label' => 'Training']],
            $this->signedInAs($manager)->getJson('/api/v1/hr/time-clock/week?on=2026-09-22')->assertOk()->json('data.day_tags'),
        );
    }

    /** Absent is UNCHANGED; null clears; a day with nothing left on it is gone. */
    public function test_keys_are_independent_and_an_emptied_day_is_deleted(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $url = "/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22";

        $this->signedInAs($manager)->putJson($url, ['tag' => 'office', 'note' => 'In early'])->assertOk();
        $this->signedInAs($manager)->putJson($url, ['tag' => 'home'])->assertOk();

        $day = $this->inTenant($alpha['org'], fn () => TimeClockDay::query()->sole());
        $this->assertSame('home', $day->tag);
        $this->assertSame('In early', $day->note, 'a tag-only change leaves the note alone');

        $this->signedInAs($manager)->putJson($url, ['tag' => null, 'note' => null])->assertOk();

        $this->assertSame(0, TimeClockDay::query()->withoutGlobalScopes()->count());
    }

    /** Vacation next week is the commonest reason to open this; hours not yet worked are not. */
    public function test_a_future_day_takes_a_tag_but_not_extra_hours(): void
    {
        [, $manager, $tech] = $this->tenant();

        $this->signedInAs($manager)
            ->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-30", ['tag' => 'vacation'])
            ->assertOk();

        $this->signedInAs($manager)
            ->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-30", ['extra_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonPath('errors.extra_minutes.0', 'Extra hours cannot be added to a day that has not happened yet.');
    }

    public function test_only_the_six_tags(): void
    {
        [, $manager, $tech] = $this->tenant();

        $this->signedInAs($manager)
            ->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22", ['tag' => 'beach'])
            ->assertStatus(422);
    }

    // ── Who may, and whose ───────────────────────────────────────

    /**
     * The tab alone reads; it does not write. The subject holds the OTHER
     * candidate, `work_hours.general.view`, and is shown to reach the pane first.
     */
    public function test_the_work_hours_tab_alone_cannot_tag_or_force(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $reader = $this->userWithRole($alpha, 'Dispatch', ['work_hours.general.view', 'work_hours.all'], 'dispatch@alpha.test');
        $shift = $this->shift($alpha['org'], $tech, '2026-09-24 13:00:00', null);

        $this->signedInAs($reader)->getJson('/api/v1/hr/time-clock/week')->assertOk();

        $this->signedInAs($reader)->postJson("/api/v1/hr/time-clock/shifts/{$shift->id}/clock-out")->assertForbidden();
        $this->signedInAs($reader)->putJson("/api/v1/hr/time-clock/people/{$tech->id}/days/2026-09-22", ['tag' => 'sick'])->assertForbidden();

        $this->assertNull($this->fresh($shift)->clocked_out_at);
    }

    /**
     * A branch manager holding the grant cannot reach another branch's people
     * by pasting an id — the hole the first version of corrections had, which
     * checked the organization and not the franchise. 404, so the id is not
     * confirmed to exist. A control proves the same manager DOES reach their
     * own branch, or the refusals would prove only a fixture that reaches
     * nothing.
     */
    public function test_another_franchise_is_out_of_reach_for_every_correction(): void
    {
        [$alpha] = $this->tenant();
        $elsewhere = $this->extraFranchise($alpha, 'Alpha F2');

        $branch = $this->userWithRole(
            $alpha, 'Branch', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'branch@alpha.test', onlyAt: $alpha['franchise'],
        );
        $mine = $this->userWithRole($alpha, 'Mine', ['time_clock.view'], 'mine@alpha.test', onlyAt: $alpha['franchise']);
        $theirs = $this->userWithRole($alpha, 'Theirs', ['time_clock.view'], 'theirs@alpha.test', onlyAt: $elsewhere);

        $myShift = $this->shift($alpha['org'], $mine, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $theirShift = $this->shift($alpha['org'], $theirs, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $theirOpen = $this->shift($alpha['org'], $theirs, '2026-09-24 13:00:00', null);

        // Control: my own branch.
        $this->signedInAs($branch)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$myShift->id}", ['clocked_out_at' => '2026-09-22T20:00:00Z'])
            ->assertOk();

        $this->signedInAs($branch)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$theirShift->id}", ['clocked_out_at' => '2026-09-22T20:00:00Z'])
            ->assertNotFound();
        $this->signedInAs($branch)->deleteJson("/api/v1/hr/time-clock/shifts/{$theirShift->id}")->assertNotFound();
        $this->signedInAs($branch)->postJson("/api/v1/hr/time-clock/shifts/{$theirOpen->id}/clock-out")->assertNotFound();
        $this->signedInAs($branch)
            ->putJson("/api/v1/hr/time-clock/people/{$theirs->id}/days/2026-09-22", ['tag' => 'absent'])
            ->assertNotFound();

        $this->assertSame('2026-09-22 21:00:00', $this->fresh($theirShift)->clocked_out_at->toDateTimeString());
        $this->assertNull($this->fresh($theirOpen)->clocked_out_at);
        $this->assertSame(0, TimeClockDay::query()->withoutGlobalScopes()->count());
    }

    /**
     * The edit dialog sends wall clock, and it means the ORGANIZATION'S wall
     * clock. New York in September is UTC-4, so 17:20 there is 21:20 UTC —
     * whatever zone the laptop sending it is in.
     */
    public function test_a_correction_in_wall_clock_is_read_in_the_organizations_zone(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->shift($alpha['org'], $tech, '2026-09-23 21:00:14', null);

        $week = $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}?on=2026-09-23", ['clocked_out_at' => '2026-09-23 17:20'])
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-09-23 21:20:00', $this->fresh($shift)->clocked_out_at->toDateTimeString());

        // And the week hands the same shape back, for the picker to start from.
        $row = collect($week['people'])->firstWhere('id', $tech->id)['shifts'][0];
        $this->assertSame('2026-09-23 17:00', $row['clocked_in_local']);
        $this->assertSame('2026-09-23 17:20', $row['clocked_out_local']);
    }

    // ── The lock screen's sentence ───────────────────────────────

    public function test_the_block_says_when_the_day_is_over(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $this->inTenant($alpha['org'], fn () => $tech->forceFill(['work_schedule' => [
            'timezone' => 'America/New_York',
            'days' => ['thursday' => ['start' => '08:00', 'end' => '17:00']],
        ]])->save());

        // 14:00 local: inside the day.
        $this->assertFalse($this->signedInAs($tech)->getJson('/api/v1/time-clock/me')->json('data.time_clock.after_hours'));

        // 17:00:14 local: the moment Adeline pressed Clock In.
        Carbon::setTestNow(Carbon::parse('2026-09-24 21:00:14', 'UTC'));
        $this->assertTrue($this->signedInAs($tech)->getJson('/api/v1/time-clock/me')->json('data.time_clock.after_hours'));
    }

    /**
     * The lock screen's date is the day in the schedule's zone, in the
     * organization's language. At 22:00 New York it is already Friday in UTC;
     * the label must still say Thursday.
     */
    public function test_the_block_names_today_in_the_schedules_zone_and_the_orgs_language(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $this->inTenant($alpha['org'], fn () => $tech->forceFill(['work_schedule' => [
            'timezone' => 'America/New_York',
            'days' => ['thursday' => ['start' => '08:00', 'end' => '17:00']],
        ]])->save());
        Carbon::setTestNow(Carbon::parse('2026-09-25 02:00:00', 'UTC'));

        $label = fn () => $this->signedInAs($tech)->getJson('/api/v1/time-clock/me')->json('data.time_clock.today_label');
        $this->assertStringStartsWith('Thursday', $label());

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('locale', $alpha['org'], 'es'));
        $this->assertStringStartsWith('jueves', $label());
    }

    /** @return array{0: array, 1: User, 2: User} */
    private function tenant(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('time_clock.enabled', $alpha['org'], true);
            app(SettingsResolver::class)->set('timezone', $alpha['org'], 'America/New_York');
        });

        return [
            $alpha,
            $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'hr@alpha.test'),
            $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test'),
        ];
    }

    private function shift(Organization $org, User $user, string $in, ?string $out): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
            'scheduled_end_at' => $out === null ? '2026-09-24 21:00:00' : null,
        ]));
    }

    private function fresh(TimeClockShift $shift): TimeClockShift
    {
        return TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());
    }
}
