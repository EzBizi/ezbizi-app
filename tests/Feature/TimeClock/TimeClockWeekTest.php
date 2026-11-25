<?php

namespace Tests\Feature\TimeClock;

use App\Models\Company;
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
 * Work Hours: one week, grouped by person, with the totals (2026-09-17).
 *
 * The day list answers "who was in on this date". This answers "what did each
 * person work this week", which is the question the screen is built around:
 * one row per person, their shifts underneath, hours totalled.
 */
class TimeClockWeekTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Wednesday 2026-09-16, 11:00 in New York (EDT, UTC-4).
     * Sunday-start week is 13th-19th; Monday-start is 14th-20th.
     */
    private function midweek(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 15:00:00', 'UTC'));
    }

    /** @return array{0: array, 1: User} */
    private function tenantWithAWeek(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $worker = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');

        $this->inTenant($alpha['org'], function () use ($alpha, $worker) {
            foreach ([
                // Saturday the 12th: the week before, under either rule.
                ['2026-09-12 13:00:00', '2026-09-12 14:00:00'],
                // Sunday the 13th: inside a Sunday week, outside a Monday one.
                ['2026-09-13 14:00:00', '2026-09-13 16:00:00'],
                // Monday 09:00-17:00 and Tuesday 09:00-12:00, both local.
                ['2026-09-14 13:00:00', '2026-09-14 21:00:00'],
                ['2026-09-15 13:00:00', '2026-09-15 16:00:00'],
                // Today, still running: two hours so far at 11:00.
                ['2026-09-16 13:00:00', null],
            ] as [$in, $out]) {
                TimeClockShift::query()->create([
                    'organization_id' => $alpha['org']->id,
                    'user_id' => $worker->id,
                    'clocked_in_at' => $in,
                    'clocked_out_at' => $out,
                ]);
            }
        });

        return [$alpha, $worker];
    }

    public function test_the_week_runs_sunday_to_saturday_and_totals_the_hours(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $page = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data');

        $this->assertSame('2026-09-13', $page['week_start']);
        $this->assertSame('2026-09-19', $page['week_end']);
        $this->assertSame('sunday', $page['starts_on']);
        $this->assertSame('America/New_York', $page['timezone']);

        $person = collect($page['people'])->firstWhere('id', $worker->id);

        // Sunday 2h + Monday 8h + Tuesday 3h + two hours open so far = 15h.
        $this->assertSame(15 * 3600, $person['total_seconds']);
        $this->assertSame(4, $person['days_worked']);
        $this->assertSame((15 * 3600) / 4, $person['average_seconds']);
        $this->assertTrue($person['open']);

        // Saturday the 12th belongs to the week before and is not here.
        $this->assertCount(4, $person['shifts']);
        $this->assertSame('2026-09-13', $person['shifts'][0]['date']);
        $this->assertSame('2026-09-16', $person['shifts'][3]['date']);

        // An open shift reports what has been worked so far, not null, because
        // it is summed into the total the screen shows beside it.
        $this->assertSame(2 * 3600, $person['shifts'][3]['duration_seconds']);
        $this->assertTrue($person['shifts'][3]['open']);
        $this->assertNull($person['shifts'][3]['clocked_out']);
        $this->assertSame('9:00 AM', $person['shifts'][3]['clocked_in']);
    }

    /** Each person carries their role, for the list's role filter (2026-09-30). */
    public function test_each_person_carries_their_role(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $people = collect($this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')->assertOk()->json('data.people'));

        $this->assertSame(['id' => $worker->role_id, 'name' => 'Tech'], $people->firstWhere('id', $worker->id)['role']);
        $this->assertSame('Hr', $people->firstWhere('id', $viewer->id)['role']['name']);
    }

    /**
     * The screen follows the top-bar view, as HR's does (2026-10-05): on a
     * company nobody is placed in it lists nobody. The control is the same
     * reader on the whole organization.
     */
    public function test_the_week_lists_only_the_people_in_view(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $this->midweek();
        $empty = $this->inTenant($alpha['org'], fn () => Company::query()->create([
            'organization_id' => $alpha['org']->id, 'name' => 'Nobody Here',
        ]));

        $ids = fn ($response) => array_column($response->assertOk()->json('data.people'), 'id');

        $this->assertContains($worker->id, $ids($this->signedInAs($alpha['admin'])->getJson('/api/v1/hr/time-clock/week')));
        // Nobody else, but always yourself (Jose, 2026-11-25: "when ur looking
        // for yourself you always show up no matter where").
        $this->assertSame([$alpha['admin']->id], $ids($this->signedInAs($alpha['admin'])
            ->withHeader('X-EzBizi-View', 'company:'.$empty->id)
            ->getJson('/api/v1/hr/time-clock/week')));
    }

    public function test_the_organization_can_start_its_week_on_another_day(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $this->set($alpha['org'], 'time_clock.week_starts_on', 'monday');
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $page = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data');

        $this->assertSame('2026-09-14', $page['week_start']);
        $this->assertSame('2026-09-20', $page['week_end']);
        $this->assertSame('monday', $page['starts_on']);

        $person = collect($page['people'])->firstWhere('id', $worker->id);

        // Sunday the 13th has dropped out of the window with its two hours.
        $this->assertSame(13 * 3600, $person['total_seconds']);
        $this->assertSame(3, $person['days_worked']);
        $this->assertCount(3, $person['shifts']);
    }

    public function test_on_names_any_day_inside_the_week_it_wants(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        // The Saturday before, asked for by a date in that week.
        $page = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week?on=2026-09-09')
            ->assertOk()->json('data');

        $this->assertSame('2026-09-06', $page['week_start']);
        $this->assertSame('2026-09-12', $page['week_end']);

        $person = collect($page['people'])->firstWhere('id', $worker->id);
        $this->assertCount(1, $person['shifts']);
        $this->assertSame(3600, $person['total_seconds']);
        $this->assertFalse($person['open']);
    }

    public function test_everybody_in_reach_is_listed_even_with_nothing_worked(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $idle = $this->userWithRole($alpha, 'Idle', ['time_clock.view'], 'idle@alpha.test');
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');

        $beta = $this->provisionTenant('Beta');
        $this->set($beta['org'], 'time_clock.enabled', true);

        $this->midweek();

        $people = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data.people');

        $ids = array_column($people, 'id');
        $this->assertContains($worker->id, $ids);
        $this->assertContains($idle->id, $ids, 'the sidebar lists everyone, not only who punched');
        $this->assertNotContains($beta['admin']->id, $ids, 'another organization never appears');

        $nothing = collect($people)->firstWhere('id', $idle->id);
        $this->assertSame(0, $nothing['total_seconds']);
        $this->assertSame(0, $nothing['days_worked']);
        $this->assertSame(0, $nothing['average_seconds']);
        $this->assertSame([], $nothing['shifts']);
        $this->assertFalse($nothing['open']);
    }

    public function test_work_hours_without_everyones_hours_shows_only_your_own(): void
    {
        [$alpha, $worker] = $this->tenantWithAWeek();
        $self = $this->userWithRole($alpha, 'Dispatch', ['work_hours.general.view', 'time_clock.view'], 'dispatch@alpha.test');
        $all = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');

        $this->midweek();

        $mine = array_column($this->signedInAs($self)->getJson('/api/v1/hr/time-clock/week')->assertOk()->json('data.people'), 'id');
        $this->assertSame([$self->id], $mine);

        $everyone = array_column($this->signedInAs($all)->getJson('/api/v1/hr/time-clock/week')->assertOk()->json('data.people'), 'id');
        $this->assertContains($worker->id, $everyone);
    }

    public function test_a_franchise_manager_does_not_see_a_sibling_franchises_people(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $other = $this->extraFranchise($alpha, 'Alpha F2');

        $mine = $this->userWithRole($alpha, 'Mine', ['time_clock.view'], 'mine@alpha.test', onlyAt: $alpha['franchise']);
        $theirs = $this->userWithRole($alpha, 'Theirs', ['time_clock.view'], 'theirs@alpha.test', onlyAt: $other);
        $manager = $this->userWithRole($alpha, 'Manager', ['work_hours.general.view', 'work_hours.all'], 'manager@alpha.test', onlyAt: $alpha['franchise']);

        $this->midweek();

        $ids = array_column(
            $this->signedInAs($manager)->getJson('/api/v1/hr/time-clock/week')->assertOk()->json('data.people'),
            'id'
        );

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_the_week_needs_the_work_hours_tab_and_the_organization_switch(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');

        $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            // 409, not 403: the feature being off is not a permission failure,
            // and this viewer HOLDS the permission.
            ->assertStatus(409)
            ->assertJsonPath('message', 'Clock in is switched off for this organization.');

        $this->set($alpha['org'], 'time_clock.enabled', true);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech2@alpha.test');

        // Same as the day list: the rule is that punching does not grant
        // reading, and the sentence is Settings' middleware to word.
        $this->signedInAs($puncher)->getJson('/api/v1/hr/time-clock/week')
            ->assertForbidden();
    }

    /**
     * The seven headings, named on the server (2026-09-17).
     *
     * UI/UX asked for these rather than hardcode a fourth English weekday array
     * in a screen that ships in Spanish. Empty days are included because a day
     * nobody worked still needs a heading saying so.
     */
    public function test_the_week_names_all_seven_days_in_the_organizations_language(): void
    {
        [$alpha] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $days = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data.days');

        $this->assertCount(7, $days, 'every day, including the ones nobody worked');
        $this->assertSame('2026-09-13', $days[0]['date']);
        $this->assertSame('sunday', $days[0]['weekday']);
        $this->assertSame('2026-09-19', $days[6]['date']);
        $this->assertSame('saturday', $days[6]['weekday']);

        // Default date_format is M/D/YYYY, so the month leads.
        $this->assertSame('Sunday, Sep 13', $days[0]['label']);

        // Thursday the 17th is inside the week and nobody worked it.
        $this->assertSame('2026-09-17', $days[4]['date']);
    }

    public function test_a_spanish_organization_gets_spanish_headings_day_first(): void
    {
        [$alpha] = $this->tenantWithAWeek();
        $this->set($alpha['org'], 'locale', 'es');
        $this->set($alpha['org'], 'date_format', 'D/M/YYYY');
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $days = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data.days');

        // The key stays English for anything that branches on the day.
        $this->assertSame('sunday', $days[0]['weekday']);

        // The label is the part a person reads, and it is not English.
        $this->assertStringNotContainsStringIgnoringCase('Sunday', $days[0]['label']);
        $this->assertStringContainsStringIgnoringCase('domingo', $days[0]['label']);
        // Day before month, because this organization writes dates that way.
        $this->assertMatchesRegularExpression('/13/', $days[0]['label']);
    }

    /**
     * Which days have not happened yet (2026-09-17).
     *
     * Jose does not want the rest of the week drawn before it arrives. The
     * client cannot decide that: "future" is against today in the
     * ORGANIZATION'S clock, and the only today it has is the device's.
     */
    public function test_days_say_whether_they_are_still_to_come(): void
    {
        [$alpha] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek(); // Wednesday the 16th, 11:00 in New York.

        $days = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week')
            ->assertOk()->json('data.days');

        $future = [];
        foreach ($days as $day) {
            $future[$day['date']] = $day['in_future'];
        }

        $this->assertSame([
            '2026-09-13' => false,
            '2026-09-14' => false,
            '2026-09-15' => false,
            // Today is not the future.
            '2026-09-16' => false,
            '2026-09-17' => true,
            '2026-09-18' => true,
            '2026-09-19' => true,
        ], $future);
    }

    public function test_a_week_that_is_over_has_no_days_still_to_come(): void
    {
        [$alpha] = $this->tenantWithAWeek();
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $this->midweek();

        $days = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/week?on=2026-09-09')
            ->assertOk()->json('data.days');

        $this->assertSame([false, false, false, false, false, false, false], array_column($days, 'in_future'));
    }

    /**
     * The two refusals are told apart by their status, not by reading English.
     *
     * This is the test Settings could not write on 2026-09-17: both answered
     * 403, so a permission suite full of negatives passed identically against a
     * feature that was switched off entirely. One assertion, two causes.
     */
    public function test_no_permission_and_no_feature_are_different_refusals(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $granted = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all'], 'hr@alpha.test');
        $ungranted = $this->userWithRole($alpha, 'Other', ['customers.view'], 'other@alpha.test');

        // Clock in is OFF for this organization, and nobody has been granted
        // anything since. Both people are refused; they must not look alike.
        $this->signedInAs($ungranted)->getJson('/api/v1/hr/time-clock/week')
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');

        $this->signedInAs($granted)->getJson('/api/v1/hr/time-clock/week')
            ->assertStatus(409);

        // And with the switch on, the one holding the grant gets through, which
        // is the case whose absence made the negatives meaningless.
        $this->set($alpha['org'], 'time_clock.enabled', true);

        $this->signedInAs($granted)->getJson('/api/v1/hr/time-clock/week')->assertOk();
        $this->signedInAs($ungranted)->getJson('/api/v1/hr/time-clock/week')->assertStatus(403);
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
