<?php

namespace Tests\Feature\TimeClock;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockDayListTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_org_off_refuses_the_day_list(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);

        $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock')
            // 409, not 403: the feature being off is not a permission failure,
            // and this viewer HOLDS the permission.
            ->assertStatus(409)
            ->assertJsonPath('message', 'Clock in is switched off for this organization.');
    }

    public function test_punching_does_not_let_you_read_everybodys_hours(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        /*
         * The rule this pins is mine: being allowed to PUNCH is not being
         * allowed to read everybody's hours. Which sentence comes back is not
         * — since per-tab permissions (Settings, 2026-09-17) the tab gate
         * answers before the policy does, and that wording belongs to their
         * middleware and is tested there. Pinning it here would make this file
         * fail the day they improve it.
         */
        $this->signedInAs($puncher)->getJson('/api/v1/hr/time-clock')
            ->assertForbidden();
    }

    public function test_empty_day_uses_view_local_today_and_names_the_zone(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);

        // 03:00 UTC is still 23:00 the previous calendar day in the default
        // America/New_York (August is EDT, UTC−4). Device/UTC "today" would be
        // 2026-08-25.
        //
        // The INSTANT moved with the default, and it had to. This was 04:00
        // UTC, which was 23:00 on the 24th at a fixed UTC−5 but is exactly
        // midnight on the 25th in EDT — so the local day and the UTC day would
        // have agreed and the test would have passed while proving the
        // opposite of what it is for. Re-deriving the instant keeps the
        // premise; swapping the expected date to '2026-08-25' would have
        // silently thrown the test away.
        Carbon::setTestNow(Carbon::parse('2026-08-25 03:00:00', 'UTC'));

        $page = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-24', $page['date']);
        $this->assertSame('America/New_York', $page['timezone']);
        $this->assertSame('Eastern — New York', $page['timezone_label']);
        $this->assertSame([], $page['shifts']);
    }

    public function test_two_shifts_the_same_day_are_two_rows_newest_first(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);
        $puncher = $this->userWithRole($alpha, 'Zed', ['time_clock.view'], 'zed@alpha.test');

        $this->inTenant($alpha['org'], function () use ($alpha, $puncher) {
            TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => '2026-08-25 12:00:00',
                'clocked_out_at' => '2026-08-25 16:00:00',
            ]);
            TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => '2026-08-25 18:00:00',
                'clocked_out_at' => '2026-08-25 20:00:00',
            ]);
        });

        $shifts = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts');

        $this->assertCount(2, $shifts);
        $this->assertSame('2026-08-25T18:00:00Z', $shifts[0]['clocked_in_at']);
        $this->assertSame('2026-08-25T12:00:00Z', $shifts[1]['clocked_in_at']);
        $this->assertSame($puncher->id, $shifts[0]['user']['id']);
        $this->assertSame('Zed', $shifts[0]['user']['name']);
        $this->assertSame(14400, $shifts[1]['duration_seconds']);
        $this->assertFalse($shifts[0]['open']);
    }

    public function test_the_list_buckets_by_view_timezone_not_utc_and_duration_does_not_move(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);

        $texas = $this->inTenant($alpha['org'], function () use ($alpha) {
            $franchise = Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id' => $alpha['company']->id,
                'name' => 'Texas',
            ]);
            app(SettingsResolver::class)->set('timezone', $franchise, 'Etc/GMT+6');

            return $franchise;
        });

        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');

        // Spec §12: a punch just after midnight in the org's zone is the
        // previous day in the franchise's. 05:30 UTC is 01:30 in New York on
        // an EDT date (UTC−4) and 23:30 the day before in Etc/GMT+6. The gap
        // used to be one hour, when the org default was a fixed UTC−5; it is
        // two now, and the contrast this test is built on is unchanged.
        $inAt = Carbon::parse('2026-08-25 05:30:00', 'UTC');
        $outAt = $inAt->copy()->addHours(8);

        $this->inTenant($alpha['org'], function () use ($alpha, $puncher, $inAt, $outAt) {
            TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => $inAt,
                'clocked_out_at' => $outAt,
            ]);
        });

        $orgDay = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-25', $orgDay['date']);
        $this->assertSame('America/New_York', $orgDay['timezone']);
        $this->assertSame('Eastern — New York', $orgDay['timezone_label']);
        $this->assertCount(1, $orgDay['shifts']);
        $this->assertSame(28800, $orgDay['shifts'][0]['duration_seconds']);
        $this->assertSame('1:30 AM', $orgDay['shifts'][0]['clocked_in']);
        $this->assertSame('9:30 AM', $orgDay['shifts'][0]['clocked_out']);
        $this->assertSame('2026-08-25T05:30:00Z', $orgDay['shifts'][0]['clocked_in_at']);

        $this->standIn($viewer, 'franchise:'.$texas->id);

        $wrongDay = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-25', $wrongDay['date']);
        $this->assertSame('Etc/GMT+6', $wrongDay['timezone']);
        $this->assertSame('UTC-6', $wrongDay['timezone_label']);
        $this->assertSame([], $wrongDay['shifts']);

        $localDay = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-24')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-24', $localDay['date']);
        $this->assertCount(1, $localDay['shifts']);
        $this->assertSame(28800, $localDay['shifts'][0]['duration_seconds']);
        $this->assertSame('11:30 PM', $localDay['shifts'][0]['clocked_in']);
        $this->assertSame('7:30 AM', $localDay['shifts'][0]['clocked_out']);
    }

    public function test_an_open_shift_has_no_out_clock_and_null_duration(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');

        $this->inTenant($alpha['org'], function () use ($alpha, $puncher) {
            TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => '2026-08-25 14:00:00',
                'clocked_out_at' => null,
            ]);
        });

        $shift = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts.0');

        $this->assertTrue($shift['open']);
        $this->assertNull($shift['clocked_out_at']);
        $this->assertNull($shift['clocked_out']);
        $this->assertNull($shift['duration_seconds']);
        // 14:00 UTC in New York on an EDT date. It read 9:00 AM while the
        // default was a fixed UTC−5.
        $this->assertSame('10:00 AM', $shift['clocked_in']);
    }

    public function test_a_sibling_franchise_person_is_not_listed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id' => $alpha['company']->id,
            'name' => 'Alpha F2',
        ]));

        $viewer = $this->userWithRole(
            $alpha, 'Here', ['work_hours.general.view', 'work_hours.all'], 'here@alpha.test', onlyAt: $alpha['franchise'],
        );
        $insider = $this->userWithRole(
            $alpha, 'Insider', ['time_clock.view'], 'in@alpha.test', onlyAt: $alpha['franchise'],
        );
        $outsider = $this->userWithRole(
            $alpha, 'Outsider', ['time_clock.view'], 'out@alpha.test', onlyAt: $elsewhere,
        );

        $this->inTenant($alpha['org'], function () use ($alpha, $insider, $outsider) {
            foreach ([$insider, $outsider] as $person) {
                TimeClockShift::query()->create([
                    'organization_id' => $alpha['org']->id,
                    'user_id' => $person->id,
                    'clocked_in_at' => '2026-08-25 14:00:00',
                    'clocked_out_at' => '2026-08-25 18:00:00',
                ]);
            }
        });

        $shifts = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts');

        $ids = array_column(array_column($shifts, 'user'), 'id');
        $this->assertContains($insider->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    /**
     * A person with NO assignment at all — the case no fixture here could build.
     *
     * visibleTo() has three arms: yourself, people whose assignments are within
     * your reach, and — only when the actor can act on the organization —
     * people with no assignments at all. That third arm is the same shape as
     * the bug the Inspection session found on 2026-09-08, where a record whose
     * parent was null fell out of the franchise filter and became visible to
     * everyone. Here it is written correctly and was never once exercised.
     *
     * It could not be. userWithRole() ALWAYS creates an Assignment — org-level
     * when onlyAt is null, franchise-level otherwise — so every person in every
     * other test in this file has one, and the arm is unreachable from the
     * fixture. test_a_sibling_franchise_person_is_not_listed reads like the
     * reach test and only ever proved the assigned half.
     *
     * So this builds the weak form by hand and asserts BOTH directions in one
     * test: the org-level actor must see the orphan (or the feature is dead and
     * a green suite says nothing), and the franchise-only actor must not (or
     * the guard is gone). Both lists are collected before either is asserted,
     * so a broken first assertion cannot hide a leak in the second.
     */
    public function test_somebody_with_no_assignment_is_org_only_never_franchise_wide(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);

        $orphan = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name' => 'Unplaced',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['time_clock.view']);

            // Deliberately no Assignment row. That is the whole point.
            return User::create([
                'organization_id' => $alpha['org']->id,
                'role_id' => $role->id,
                'name' => 'Unplaced',
                'email' => 'unplaced@alpha.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
        });

        $atFranchise = $this->userWithRole(
            $alpha, 'AtFranchise', ['work_hours.general.view', 'work_hours.all'], 'atf@alpha.test', onlyAt: $alpha['franchise'],
        );
        $atOrg = $this->userWithRole($alpha, 'AtOrg', ['work_hours.general.view', 'work_hours.all'], 'atorg@alpha.test');

        $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $orphan->id,
            'clocked_in_at' => '2026-08-25 14:00:00',
            'clocked_out_at' => '2026-08-25 18:00:00',
        ]));

        $seenByFranchise = $this->idsOn($atFranchise);
        $seenByOrg = $this->idsOn($atOrg);

        $this->assertContains($orphan->id, $seenByOrg, 'an organization-wide actor must see an unplaced person');
        $this->assertNotContains($orphan->id, $seenByFranchise, 'an unplaced person must not leak to a franchise-only actor');
    }

    /** @return list<string> */
    private function idsOn(User $viewer): array
    {
        $shifts = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts');

        return array_column(array_column($shifts, 'user'), 'id');
    }

    public function test_another_organization_is_never_listed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo', 'bravo@example.test');
        $this->enableClockIn($alpha['org']);
        $this->enableClockIn($bravo['org']);

        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);
        $bravoPuncher = $this->userWithRole($bravo, 'Tech', ['time_clock.view']);

        $this->inTenant($bravo['org'], function () use ($bravo, $bravoPuncher) {
            TimeClockShift::query()->create([
                'organization_id' => $bravo['org']->id,
                'user_id' => $bravoPuncher->id,
                'clocked_in_at' => '2026-08-25 14:00:00',
                'clocked_out_at' => '2026-08-25 18:00:00',
            ]);
        });

        $shifts = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts');

        $this->assertSame([], $shifts);
    }

    public function test_overnight_still_open_from_yesterday_is_not_on_today(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');

        // 03:00 UTC = 22:00 the previous local day in UTC−5. Still open.
        $this->inTenant($alpha['org'], function () use ($alpha, $puncher) {
            TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => '2026-08-25 03:00:00',
                'clocked_out_at' => null,
            ]);
        });

        $today = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-25')
            ->assertOk()
            ->json('data.shifts');

        $this->assertSame([], $today);

        $started = $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock?on=2026-08-24')
            ->assertOk()
            ->json('data.shifts');

        $this->assertCount(1, $started);
        $this->assertTrue($started[0]['open']);
    }

    public function test_an_invalid_on_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);

        $this->assertRefusedWithSentence(
            $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock?on=yesterday'),
            'Choose a day as YYYY-MM-DD.',
        );
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }

    private function standIn(User $user, ?string $scope): void
    {
        app(TenantContext::class)->run($user->organization, function () use ($user, $scope) {
            User::query()->find($user->id)
                ->forceFill(['preferences' => $scope === null ? [] : ['navigation.scope' => $scope]])
                ->save();
        });
    }

    private function assertRefusedWithSentence(TestResponse $response, string $sentence): void
    {
        $this->assertContains($response->status(), [403, 422], $response->getContent());
        $this->assertStringContainsString($sentence, $response->getContent());
    }

    public function test_rows_carry_ended_by_outside_and_extensions(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $puncher->id,
            'clocked_in_at' => '2026-08-25 12:00:00',
            'clocked_out_at' => '2026-08-25 22:30:00',
            'scheduled_end_at' => '2026-08-25 22:30:00',
            'extensions' => 2,
            'outside_schedule' => true,
            'ended_by' => TimeClockShift::ENDED_BY_SCHEDULE,
        ]));

        $row = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock?on=2026-08-25')->assertOk()->json('data.shifts.0');

        $this->assertSame('schedule', $row['ended_by']);
        $this->assertTrue($row['outside_schedule']);
        $this->assertSame(2, $row['extensions']);
    }
}
