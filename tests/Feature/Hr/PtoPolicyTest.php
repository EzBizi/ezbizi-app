<?php

namespace Tests\Feature\Hr;

use App\Models\AutomationEvent;
use App\Models\EmploymentEvent;
use App\Support\Hr\PtoPolicy;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What somebody is allowed, and the day it opens.
 *
 * The suggestion this answers asked two things — "how much paid pto we all
 * have AND when it can be used" — and the second is the one nothing in the
 * product could answer. These tests are mostly about that half.
 */
class PtoPolicyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(): PtoPolicy
    {
        return app(PtoPolicy::class);
    }

    private function set(array $tenant, array $values): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $values) {
            foreach ($values as $key => $value) {
                app(SettingsResolver::class)->set($key, $tenant['org'], $value);
            }
        });
    }

    private function started(array $tenant, string $on): void
    {
        $this->inTenant($tenant['org'], fn () => EmploymentEvent::create([
            'organization_id' => $tenant['org']->id,
            'user_id' => $tenant['admin']->id,
            'kind' => EmploymentEvent::STARTED,
            'occurred_on' => $on,
        ]));
    }

    /** A quarter is three months, without overflow (Jose, 2026-10-21). */
    public function test_a_waiting_period_in_quarters_is_three_months_each_without_overflow(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.waiting' => 1, 'pto.waiting_unit' => 'quarter']);
        $this->started($t, '2026-01-31');

        // 31 January + 3 months is 30 April, not 1 May.
        $this->assertSame('2026-04-30', $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin'])['eligible_from']));
    }

    /**
     * Earned per quarter, less the days HR recorded as used (Jose, 2026-10-21:
     * "mark days as already used ... so existing balances start correct").
     */
    public function test_the_balance_is_whole_periods_earned_less_days_recorded_as_used(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 2, 'pto.allowance_period' => 'quarter', 'pto.reset' => 'anniversary']);
        $this->started($t, '2025-11-30');

        // Feb 28, May 30, Aug 30 have passed; Nov 30 has not: three quarters.
        $this->assertSame(6.0, $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin'])['total']));

        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$t['admin']->id}/details/pto-used",
            ['days' => 1.5, 'note' => 'Taken before EzBizi'])->assertCreated();
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$t['admin']->id}/details/pto-used",
            ['days' => 1, 'used_on' => '2026-07-04'])->assertCreated();

        $answer = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame(2.5, $answer['used']);
        $this->assertSame(3.5, $answer['left']);
    }

    /**
     * Earned per month, used by the work anniversary (Jose, 2026-10-21:
     * "started 1 Apr 2025 you have until 1 Apr 2026"), and carryover applies
     * at that reset.
     */
    public function test_days_are_used_by_the_work_anniversary_and_carry_over_at_it(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 2, 'pto.allowance_period' => 'month', 'pto.reset' => 'anniversary']);
        $this->started($t, '2025-04-01');
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$t['admin']->id}/details/pto-used",
            ['days' => 5, 'used_on' => '2025-12-01'])->assertCreated();

        // This year runs 1 Apr 2026 to 1 Apr 2027: May..Oct are six months.
        $answer = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame(12.0, $answer['total']);
        $this->assertSame(0.0, $answer['used']);
        $this->assertSame('2027-04-01', $answer['resets_on']);
        // Carryover off: last year's 24 earned less 5 used expired at the reset.
        $this->assertSame(19.0, $answer['expired']);
        $this->assertSame([['on' => '2026-04-01', 'expired' => 19.0, 'carried' => 0.0]], $answer['resets']);

        // With carryover, last year's 24 earned less 5 used comes along.
        $this->set($t, ['pto.carry_over' => true]);
        $answer = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame(19.0, $answer['carried']);
        $this->assertSame(0.0, $answer['expired']);
        $this->assertSame([['on' => '2026-04-01', 'expired' => 0.0, 'carried' => 19.0]], $answer['resets']);
        $this->assertSame(31.0, $answer['left']);
    }

    /** Used typed in is an audited adjustment, behind its own permission (Jose, 2026-10-21). */
    public function test_used_is_set_as_an_adjustment_with_a_reason_behind_its_own_permission(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 2, 'pto.allowance_period' => 'month', 'pto.reset' => 'anniversary']);
        $this->started($t, '2025-04-01');
        $person = $t['admin'];
        $url = "/api/v1/hr/people/{$person->id}/pto/used";

        $hr = $this->userWithRole($t, 'HR', ['hr.view', 'hr.edit', 'work_hours.pto.view']);
        $this->signedInAs($hr)->putJson($url, ['used' => 3, 'reason' => 'x'])->assertForbidden();
        $this->signedInAs($t['admin'])->putJson($url, ['used' => 3])->assertStatus(422);

        $this->signedInAs($t['admin'])->putJson($url, ['used' => 3, 'reason' => 'Taken before EzBizi'])
            ->assertOk()->assertJsonPath('data.used', 3)->assertJsonPath('data.left', 9)
            ->assertJsonPath('data.used_change.reason', 'Taken before EzBizi');
        $this->signedInAs($t['admin'])->putJson($url, ['used' => 12, 'reason' => 'Two weeks in August'])
            ->assertOk()->assertJsonPath('data.left', 0);

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->json('data'))->firstWhere('id', $person->id);
        $this->assertTrue($row['can_edit_used']);
        $this->assertSame([12, 3], array_map(fn ($c) => (int) $c['to'], $row['used_changes']));
        $this->assertSame('Two weeks in August', $row['used_changes'][0]['reason']);

        $events = AutomationEvent::withoutGlobalScopes()->pluck('event_key')->all();
        $this->assertSame(2, count(array_keys($events, 'user.pto_used_changed')));
        $this->assertSame(1, count(array_keys($events, 'user.pto_balance_empty')));
    }

    /** The days themselves, full or half, and never the same date twice (Jose, 2026-10-22). */
    public function test_days_off_are_recorded_by_date_and_counted_in_the_period(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 2, 'pto.allowance_period' => 'month', 'pto.reset' => 'anniversary']);
        $this->started($t, '2025-04-01');
        $url = "/api/v1/hr/people/{$t['admin']->id}/pto/days";

        $this->signedInAs($t['admin'])->postJson($url, ['days' => [
            ['date' => '2026-06-01', 'portion' => 1], ['date' => '2026-06-03', 'portion' => 0.5],
        ], 'reason' => 'Family'])->assertCreated()->assertJsonPath('data.used', 1.5)
            ->assertJsonPath('data.left', 10.5)->assertJsonPath('data.would_expire', 10.5);
        $this->signedInAs($t['admin'])->postJson($url, ['days' => [['date' => '2026-06-03', 'portion' => 1]]])
            ->assertStatus(422);

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->json('data'))->firstWhere('id', $t['admin']->id);
        $this->assertSame(['2026-06-01', '2026-06-03'], array_column($row['days_off'], 'date'));
        $this->assertSame('2026-04-01', $row['period_start']);
        $this->assertArrayHasKey('id', $row['role']);

        $this->signedInAs($t['admin'])->deleteJson("{$url}/{$row['days_off'][0]['id']}")
            ->assertOk()->assertJsonPath('data.used', 0.5);
    }

    public function test_the_daily_run_raises_available_and_reset_soon_on_their_day(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 2, 'pto.waiting' => 90,
            'pto.waiting_unit' => 'day', 'pto.reset' => 'anniversary']);
        $this->started($t, '2025-11-20');

        $this->artisan('ezbizi:pto-daily-triggers')->assertSuccessful();

        // 20 Nov 2025 + 90 days is 18 Feb 2026 (not today); the anniversary
        // 20 Nov 2026 is 30 days away.
        $events = AutomationEvent::withoutGlobalScopes()->pluck('event_key')->all();
        $this->assertContains('user.pto_reset_soon', $events);
        $this->assertNotContains('user.pto_available', $events);
    }

    /**
     * Off, and zero days, until a company says otherwise.
     *
     * The defaults are the safe ones rather than the common ones: a company
     * with no written policy must not be shown an allowance it never agreed
     * to, and six days is somebody else's contract.
     */
    public function test_a_company_that_has_said_nothing_is_offered_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertFalse($answer['enabled']);
            $this->assertSame(0, $answer['allowance']);
            $this->assertSame(0, $answer['waiting']);
            $this->assertFalse($answer['carry_over']);
        });
    }

    /**
     * The waiting period is counted from the day they started.
     *
     * Ninety days from a real start date. A contract that says "after three
     * months of continuous employment" should be entered as three months
     * rather than converted here — see the months test below for the dates
     * where those two stop agreeing.
     */
    public function test_the_eligible_date_is_the_start_date_plus_the_waiting_period(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);
        $this->started($t, '2026-08-01');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertTrue($answer['enabled']);
            $this->assertSame(6, $answer['allowance']);
            $this->assertSame('2026-08-01', $answer['started_on']);
            $this->assertSame('2026-10-30', $answer['eligible_from']);

            // Started six weeks ago against a ninety-day wait, so not yet —
            // and this is the assertion that would pass anyway if the date
            // were being invented, which is why the one below exists too.
            $this->assertFalse($answer['eligible']);
        });
    }

    /** And once the date has passed, it is open. */
    public function test_somebody_past_the_waiting_period_may_use_it(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);
        $this->started($t, '2026-01-06');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame('2026-04-06', $answer['eligible_from']);
            $this->assertTrue($answer['eligible']);
        });
    }

    /**
     * No start date means the question is unanswerable, and it says so.
     *
     * Everybody on the books before `employment_events` existed has no
     * `started` row, because that migration deliberately refused to invent
     * joining dates from `updated_at`. Computing from an invented date would
     * make somebody eligible three months early or late with nothing ever
     * saying why, so the answer is null and the refusal is to guess.
     */
    public function test_with_no_start_date_the_answer_is_unknown_rather_than_invented(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertNull($answer['started_on']);
            $this->assertNull($answer['eligible_from']);

            // NOT eligible, which is the safe direction: being told to wait
            // when you could have booked costs a conversation; being told you
            // may book when the contract says otherwise costs an argument the
            // company has already written down.
            $this->assertFalse($answer['eligible']);
        });
    }

    /**
     * The earliest start wins, not the latest.
     *
     * Somebody who left and was rehired has two `started` rows, and a waiting
     * period counted from the second would make a returning employee of six
     * years wait three months again.
     */
    public function test_a_rehired_person_counts_from_their_first_start(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.waiting' => 90]);
        $this->started($t, '2020-03-02');
        $this->started($t, '2026-09-01');

        $this->inTenant($t['org'], function () use ($t) {
            $this->assertSame('2020-03-02', $this->policy()->for($t['admin'])['started_on']);
            $this->assertTrue($this->policy()->for($t['admin'])['eligible']);
        });
    }

    /** A zero wait opens on the first day rather than never. */
    public function test_a_zero_waiting_period_is_open_immediately(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.waiting' => 0]);
        $this->started($t, '2026-09-17');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame('2026-09-17', $answer['eligible_from']);
            $this->assertTrue($answer['eligible']);
        });
    }

    /**
     * Three months is not ninety days, and the date proves which one was used.
     *
     * The start date is chosen so the two answers differ: three months from 31
     * January is 30 April, ninety days is 1 May. A conversion to days anywhere
     * between the setting and the screen lands on the wrong one of those, and
     * a person told 1 May when their contract says 30 April has been given a
     * wrong date about their own leave by a product that was guessing.
     */
    public function test_a_waiting_period_in_months_counts_months(): void
    {
        Carbon::setTestNow('2026-02-10 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, [
            'pto.enabled' => true,
            'pto.waiting' => 3,
            'pto.waiting_unit' => 'month',
        ]);
        $this->started($t, '2026-01-31');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame('2026-04-30', $answer['eligible_from']);
            $this->assertNotSame('2026-05-01', $answer['eligible_from'], 'Ninety days, not three months.');
            $this->assertSame(3, $answer['waiting']);
            $this->assertSame('month', $answer['waiting_unit']);
            $this->assertFalse($answer['eligible']);
        });
    }

    /**
     * An allowance per month stays per month. Nothing is annualised.
     *
     * One day a month is twelve a year, and EzBizi writing that twelve down
     * would be putting a figure in a contract's mouth — the policy says one a
     * month, so the answer says one a month and the screen says it back.
     */
    public function test_an_allowance_period_passes_through_unconverted(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->set($t, [
            'pto.enabled' => true,
            'pto.allowance' => 1,
            'pto.allowance_period' => 'month',
        ]);

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame(1, $answer['allowance'], 'Twelve would be us annualising somebody else’s policy.');
            $this->assertSame('month', $answer['allowance_period']);
        });
    }

    /**
     * Accumulation can wait, once, after the day they started (Jose, 2026-10-23).
     *
     * 2 days a quarter and 3 months before anything accumulates. Started
     * 1 April 2026, the quarter that finishes as that wait ends is not earned,
     * so the first work year is 6 and the second is 8. The wait is not applied
     * again. On the morning of the third anniversary both years have closed
     * and nothing new has been earned yet, so what was carried is 6 then 8.
     */
    public function test_accumulation_starts_after_a_one_time_wait_and_the_second_year_is_full(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->set($t, [
            'pto.enabled' => true,
            'pto.allowance' => 2,
            'pto.allowance_period' => 'quarter',
            'pto.reset' => 'anniversary',
            'pto.carry_over' => true,
            'pto.accrue_after' => 3,
            'pto.accrue_after_unit' => 'month',
        ]);
        $this->started($t, '2026-04-01');

        $first = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame(3, $first['accrue_after']);
        $this->assertSame('month', $first['accrue_after_unit']);
        $this->assertSame('2026-07-01', $first['accrues_from']);
        // October has arrived; July was the end of the wait, so only 2 days,
        // and the timeline can say the day they arrived.
        $this->assertSame(2.0, $first['total']);
        $this->assertSame(0.0, $first['carried']);
        $this->assertSame([['on' => '2026-10-01', 'days' => 2]], $first['earned']);

        Carbon::setTestNow('2028-04-01 12:00:00');
        $closed = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame(0.0, $closed['expired']);
        $this->assertSame(14.0, $closed['carried']);
        $this->assertSame(14.0, $closed['total']);
        $this->assertSame([
            ['on' => '2028-04-01', 'expired' => 0.0, 'carried' => 14.0],
            ['on' => '2027-04-01', 'expired' => 0.0, 'carried' => 6.0],
        ], $closed['resets']);
    }

    /**
     * Ninety days is not three months, and the first year shows which was used.
     *
     * From 31 January 2026, three months starts accumulation on 30 April and
     * the first year earns 6. Ninety days starts it on 1 May, and that first
     * year earns 4. Converting one into the other would hand both people the
     * same balance.
     */
    public function test_ninety_days_before_accumulation_is_not_three_months(): void
    {
        Carbon::setTestNow('2028-01-31 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->started($t, '2026-01-31');
        $base = [
            'pto.enabled' => true,
            'pto.allowance' => 2,
            'pto.allowance_period' => 'quarter',
            'pto.reset' => 'anniversary',
            'pto.carry_over' => true,
        ];

        $this->set($t, [...$base, 'pto.accrue_after' => 3, 'pto.accrue_after_unit' => 'month']);
        $months = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame('2026-04-30', $months['accrues_from']);
        $this->assertSame(14.0, $months['carried']);

        $this->set($t, ['pto.accrue_after' => 90, 'pto.accrue_after_unit' => 'day']);
        $days = $this->inTenant($t['org'], fn () => $this->policy()->for($t['admin']));
        $this->assertSame('2026-05-01', $days['accrues_from']);
        $this->assertSame(12.0, $days['carried']);
    }

    /**
     * A zero wait opens for somebody with no start date at all.
     *
     * The case that distinguishes: everybody on the books before the
     * employment record existed has no `started` row, so there is no date to
     * count from — and when the policy opens on day one there is nothing to
     * count. Reading eligibility off the date alone tells that whole group to
     * wait for a day that can never arrive.
     */
    public function test_a_zero_wait_is_open_even_with_no_start_date(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 0]);

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertNull($answer['started_on'], 'The fixture is the person with no start date.');
            $this->assertNull($answer['eligible_from'], 'Still unknown — there is no date to give.');
            $this->assertTrue($answer['eligible'], 'Nothing to wait for, so nothing to wait on.');
        });
    }
}
