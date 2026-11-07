<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\AutomationSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Rules that run on a clock (plan §5.5, "scheduled / recurring").
 *
 * §5.5 asks for daylight saving to be settled BEFORE schedules are built, so
 * the first two tests are about that and not about the happy path. The rest are
 * the two ways a scheduler is usually wrong in production: firing twice, and
 * firing six times after an outage.
 */
class AutomationScheduleTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = [
            'organization' => $result['organization'],
            'admin' => $result['administrator'],
            'franchise' => $result['franchise'],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── daylight saving, which is the whole reason this is not a cron ──

    /**
     * THE ONE §5.5 ASKS FOR.
     *
     * 9am in New York is 14:00 UTC in January and 13:00 UTC in July. A schedule
     * that stored an offset, or resolved once at save, would give the same
     * answer twice and be an hour wrong for eight months of the year.
     *
     * Break it by resolving in UTC instead of the schedule's zone and the two
     * assertions below collide on the same hour.
     */
    public function test_nine_in_the_morning_is_a_different_instant_in_winter_and_summer(): void
    {
        $schedule = new AutomationSchedule([
            'kind' => AutomationSchedule::DAILY,
            'time' => '09:00',
            'timezone' => 'America/New_York',
        ]);

        $winter = $schedule->nextAfter(Carbon::parse('2027-01-15 00:00:00', 'UTC'));
        $summer = $schedule->nextAfter(Carbon::parse('2027-07-15 00:00:00', 'UTC'));

        $this->assertSame('14:00', $winter->format('H:i'), 'EST is UTC-5, so 9am is 14:00 UTC');
        $this->assertSame('13:00', $summer->format('H:i'), 'EDT is UTC-4, so 9am is 13:00 UTC');

        // Said explicitly: the failure this guards is the two being EQUAL.
        $this->assertNotSame($winter->format('H:i'), $summer->format('H:i'));
    }

    /**
     * A zone that genuinely has no daylight saving must NOT move.
     *
     * The positive control for the test above: without it, code that shifted
     * every schedule by an hour in summer would pass the first test and be
     * wrong everywhere else. Arizona keeps the same offset all year and so
     * does the organization default.
     */
    public function test_a_zone_without_daylight_saving_stays_put(): void
    {
        foreach (['America/Phoenix' => '16:00', 'Etc/GMT+5' => '14:00'] as $zone => $expected) {
            $schedule = new AutomationSchedule([
                'kind' => AutomationSchedule::DAILY, 'time' => '09:00', 'timezone' => $zone,
            ]);

            $winter = $schedule->nextAfter(Carbon::parse('2027-01-15 00:00:00', 'UTC'));
            $summer = $schedule->nextAfter(Carbon::parse('2027-07-15 00:00:00', 'UTC'));

            $this->assertSame($expected, $winter->format('H:i'), "{$zone} in January");
            $this->assertSame($expected, $summer->format('H:i'), "{$zone} in July");
        }
    }

    // ── the shapes ────────────────────────────────────────────────

    public function test_weekly_lands_on_the_chosen_day(): void
    {
        $schedule = new AutomationSchedule([
            'kind' => AutomationSchedule::WEEKLY,
            'time' => '09:00',
            'days' => ['monday'],
            'timezone' => 'America/New_York',
        ]);

        // A Wednesday.
        $next = $schedule->nextAfter(Carbon::parse('2027-01-13 12:00:00', 'America/New_York'));

        $this->assertSame('Monday', $next->setTimezone('America/New_York')->format('l'));
        $this->assertSame('2027-01-18', $next->setTimezone('America/New_York')->format('Y-m-d'));
    }

    /**
     * The 31st of a month with 30 days runs on the 30th, not never.
     *
     * Skipping is the other plausible reading and it is wrong: it would drop
     * February every year and four other months, which is not what anybody
     * means by "monthly".
     */
    public function test_monthly_on_the_thirty_first_runs_on_the_last_day_of_a_short_month(): void
    {
        $schedule = new AutomationSchedule([
            'kind' => AutomationSchedule::MONTHLY,
            'time' => '09:00',
            'day_of_month' => 31,
            'timezone' => 'America/New_York',
        ]);

        $next = $schedule->nextAfter(Carbon::parse('2027-02-01 12:00:00', 'America/New_York'));

        $this->assertSame('2027-02-28', $next->setTimezone('America/New_York')->format('Y-m-d'));
    }

    // ── the two ways a scheduler goes wrong in production ─────────

    /**
     * An outage costs ONE late run, not one per minute it lasted.
     *
     * A rule six hours overdue must fire once and then point at a FUTURE
     * occurrence. Advancing from the missed time instead of from now is how a
     * six-hour outage turns into six identical reports landing at once.
     */
    public function test_a_rule_that_is_hours_overdue_fires_once_and_moves_ahead(): void
    {
        $automation = $this->scheduledRule(['kind' => 'daily', 'time' => '09:00', 'timezone' => 'America/New_York']);

        // Six hours past its due time.
        $this->inTenant(fn () => $automation->forceFill([
            'next_run_at' => now()->subHours(6), 'is_active' => true,
        ])->save());

        $this->artisan('ezbizi:dispatch-due-automations')->assertExitCode(0);

        $this->assertSame(1, $this->raisedCount(), 'one late run, not six');

        $this->inTenant(function () use ($automation) {
            $this->assertTrue(
                $automation->fresh()->next_run_at->isFuture(),
                'the rule must be pointing at a run that has not happened yet',
            );
        });

        // And a second sweep in the same minute raises nothing more.
        $this->artisan('ezbizi:dispatch-due-automations')->assertExitCode(0);
        $this->assertSame(1, $this->raisedCount(), 'a second sweep must not fire it again');
    }

    /**
     * The rule is the subject, and that is what stops a scheduled notify
     * reaching everybody.
     *
     * A schedule is about no record, so a subject-less event would fall through
     * `NotificationDispatcher::audienceFor` to EVERY ACTIVE USER. Break it by
     * passing null as the subject in DispatchDueAutomations and this goes red.
     */
    public function test_the_event_carries_the_rule_so_its_scope_decides_who_hears(): void
    {
        $automation = $this->scheduledRule(
            ['kind' => 'daily', 'time' => '09:00', 'timezone' => 'America/New_York'],
            scope: ['franchise', $this->org['franchise']->id],
        );

        $this->inTenant(fn () => $automation->forceFill([
            'next_run_at' => now()->subMinute(), 'is_active' => true,
        ])->save());

        $this->artisan('ezbizi:dispatch-due-automations')->assertExitCode(0);

        $this->inTenant(function () use ($automation) {
            $event = AutomationEvent::where('event_key', 'automation.schedule_reached')->firstOrFail();

            $this->assertSame(Automation::class, $event->subject_type);
            $this->assertSame($automation->getKey(), $event->subject_id);

            $this->assertSame(
                ['franchise', $this->org['franchise']->id],
                $automation->fresh()->automationScope(),
                'a franchise rule must answer its own franchise, not the whole organization',
            );
        });
    }

    /**
     * One rule's tick runs that rule only. Matching on the trigger alone ran
     * every scheduled rule in scope at every rule's time: A-1's 8 PM report
     * brought the 1 AM, 8 AM and Sunday ones with it (2026-11-06).
     */
    public function test_a_rules_tick_runs_that_rule_and_no_other_scheduled_rule(): void
    {
        $due = $this->scheduledRule(['kind' => 'daily', 'time' => '20:00', 'timezone' => 'America/New_York']);
        $later = $this->scheduledRule(['kind' => 'daily', 'time' => '01:00', 'timezone' => 'America/New_York']);
        $this->inTenant(function () use ($due, $later) {
            $due->forceFill(['next_run_at' => now()->subMinute(), 'is_active' => true])->save();
            $later->forceFill(['next_run_at' => now()->addHours(5), 'is_active' => true])->save();
        });

        $this->artisan('ezbizi:dispatch-due-automations')->assertExitCode(0);

        $this->inTenant(function () use ($due) {
            $event = AutomationEvent::where('event_key', 'automation.schedule_reached')->firstOrFail();
            app(AutomationEngine::class)->deliver($event->fresh());

            $this->assertSame([$due->getKey()], AutomationRun::where('event_id', $event->id)->pluck('automation_id')->all());
        });
    }

    // ── refused at save, because both failures are silent ─────────

    public function test_a_schedule_that_can_never_come_round_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one day of the week');

        new AutomationSchedule([
            'kind' => AutomationSchedule::WEEKLY,
            'time' => '09:00',
            'days' => [],
            'timezone' => 'America/New_York',
        ]);
    }

    public function test_the_clock_trigger_without_a_schedule_is_refused_at_save(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Every Monday',
            'trigger_key' => 'automation.schedule_reached',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertStatus(422)->assertJsonPath(
            'errors.schedule.0',
            'A rule that runs on a clock needs a time to run at.',
        );
    }

    /**
     * A saved clock rule reads back WITH its schedule. Without it the builder
     * opened the rule with no timezone and would not save it (Jose, 2026-09-28:
     * "can't save for some reason").
     */
    public function test_a_scheduled_rule_reads_back_with_its_schedule(): void
    {
        $schedule = ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00', 'timezone' => 'America/New_York'];

        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Weekly hours',
            'trigger_key' => 'automation.schedule_reached',
            'schedule' => $schedule,
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        $read = $this->as()->getJson("/api/v1/automations/{$id}")->assertOk()->json('data.schedule');

        $this->assertEqualsCanonicalizing($schedule, $read);
    }

    public function test_a_schedule_on_an_event_trigger_is_refused_at_save(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Confused',
            'trigger_key' => 'job.created',
            'schedule' => ['kind' => 'daily', 'time' => '09:00', 'timezone' => 'America/New_York'],
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertStatus(422);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->org['organization'], $callback);
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->org['admin']->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function raisedCount(): int
    {
        return (int) AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'automation.schedule_reached')
            ->count();
    }

    private function scheduledRule(array $schedule, ?array $scope = null): Automation
    {
        return $this->inTenant(function () use ($schedule, $scope) {
            $automation = Automation::create([
                'organization_id' => $this->org['organization']->id,
                'name' => 'On a clock',
                'trigger_key' => 'automation.schedule_reached',
                'schedule' => $schedule,
                'scope_type' => $scope[0] ?? null,
                'scope_id' => $scope[1] ?? null,
                'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $this->org['organization']->id,
                'automation_id' => $automation->id,
                'version' => 1,
                'conditions' => null,
                'actions' => [['type' => 'notify', 'message' => 'due']],
                'created_at' => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();

            return $automation->fresh();
        });
    }
}
