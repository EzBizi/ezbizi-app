<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\JobsGlance;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jobs dashboard glance: pie of statuses under the caller's reach (snapshot),
 * plus today / upcoming when something is scheduled. No money (Jose,
 * 2026-09-29).
 */
class JobsGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /** A second franchise with its own user, reaching only that franchise. */
    private function secondBranch(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    private function customer(Franchise $franchise, string $name = 'Person'): Customer
    {
        return $this->inTenant(fn () => Customer::create([
            'owning_franchise_id' => $franchise->id,
            'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => $name,
        ]));
    }

    /**
     * @param  bool  $earned  Stamp `earned_at`, i.e. this job is revenue.
     *
     * `earned_at` is deliberately NOT fillable — `JobsController::update`
     * stamps it once, at the transition into an earned status, and that is the
     * only thing allowed to. A test that needs a job to COUNT as revenue has to
     * say so, because since 2026-09-23 a completed status alone does not: the
     * status a job holds today is a read-time answer, and revenue is a question
     * about the past (§8.1).
     *
     * It is stamped to `created_at` rather than to `now()` so the UTC-boundary
     * test below keeps meaning what it meant — its two jobs are built four hours
     * apart on purpose, and a `now()` here would collapse them onto whatever the
     * clock said last.
     */
    private function job(Franchise $franchise, array $attributes = [], bool $earned = false): Job
    {
        return $this->inTenant(function () use ($franchise, $attributes, $earned) {
            $job = Job::create($attributes + [
                'franchise_id' => $franchise->id,
                'customer_id'  => $this->customer($franchise)->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
            ]);

            if ($earned) {
                $job->earned_at = $job->created_at;
                $job->save();
            }

            return $job;
        });
    }

    /**
     * What this organization counts as earned (§8.2).
     *
     * Was a per-user dashboard option until 2026-09-23. Passing
     * `['earned_statuses' => [...]]` into resolve() no longer decides anything,
     * so a test that wants revenue sets the organization's list here.
     *
     * @param  list<string>  $statuses
     */
    private function earnedStatuses(array $statuses): void
    {
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('jobs.earned_statuses', $this->alpha['org'], $statuses));
    }

    public function test_a_branch_user_sees_only_their_jobs_on_the_pie(): void
    {
        [$second, $branch] = $this->secondBranch();
        $this->job($this->alpha['franchise']);
        $this->job($this->alpha['franchise']);
        $this->job($second);
        $payload = app(JobsGlance::class)->resolve($branch, DashboardPeriod::for($branch), [])->toArray();
        $this->assertSame(1, array_sum(array_column($payload['data']['slices'], 'total')));
    }

    public function test_zero_jobs_is_a_sentence_not_slices(): void
    {
        $payload = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No jobs to show.', $payload['data']['sentence']);
    }

    public function test_the_pie_ignores_the_dashboard_period(): void
    {
        $this->job($this->alpha['franchise']);
        $week = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray();
        $year = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray();
        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }

    public function test_jobs_scheduled_for_today_are_spoken(): void
    {
        $tz = $this->alpha['franchise']->timezone ?: 'UTC';
        $todayNoon = now($tz)->copy()->startOfDay()->addHours(12)->format('Y-m-d H:i:s');
        $tomorrowNoon = now($tz)->copy()->addDay()->startOfDay()->addHours(12)->format('Y-m-d H:i:s');

        $this->job($this->alpha['franchise'], ['scheduled_for' => $todayNoon]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => $tomorrowNoon]);

        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin']),
            [],
        )->toArray();

        $this->assertContains('1 job today.', $payload['data']['sentences'] ?? []);
    }

    public function test_today_is_the_franchise_calendar_date_not_utc(): void
    {
        $tz = 'America/New_York';
        $this->inTenant(fn () => $this->alpha['franchise']->forceFill(['timezone' => $tz])->save());

        // 23:00 in New York is already the next calendar day in UTC. Counting
        // `$wall->toDateString()` without converting into $tz would use UTC's
        // date and miss this job.
        Carbon::setTestNow(Carbon::parse('2026-08-24 23:00:00', $tz));

        $this->job($this->alpha['franchise'], ['scheduled_for' => '2026-08-24 23:00:00']);

        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin']),
            [],
        )->toArray();

        $this->assertContains('1 job today.', $payload['data']['sentences'] ?? []);
    }

    /**
     * What needs somebody (Dashboard, 2026-10-05). A job dated in the past week
     * and more than a day ago that is still in the first status of the
     * organization's own list was booked and never started: `bad`, in the
     * organization's word. The day of grace keeps today's late runners off;
     * the week keeps never-closed history from drowning the row.
     */
    public function test_a_past_job_still_in_the_first_status_needs_somebody(): void
    {
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->subDays(3)]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->subDays(4)]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->subDays(3), 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->subDays(10)]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->subHours(2)]);

        $this->assertSame([['tone' => 'bad', 'text' => '2 jobs from the past week are still Scheduled.']], $this->attention());
    }

    /** Scheduling counts a day's work by area, so a job from today on without one is about to be missed. */
    public function test_an_upcoming_job_without_a_service_area_is_a_caution(): void
    {
        $area = $this->inTenant(fn () => ServiceArea::create([
            'organization_id' => $this->alpha['org']->id, 'franchise_id' => $this->alpha['franchise']->id, 'name' => 'North',
        ]));
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->addDay()]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->addDay(), 'service_area_id' => $area->id]);

        $this->assertSame([['tone' => 'caution', 'text' => '1 job from today on has no service area.']], $this->attention());
    }

    /** An organization with no service areas has nothing to place jobs into, and nothing is overdue: no rows at all. */
    public function test_nothing_to_act_on_sends_no_attention(): void
    {
        $this->job($this->alpha['franchise'], ['scheduled_for' => now()->addDay()]);

        $this->assertNull($this->attention());
    }

    /**
     * Yesterday's figure survives the load's lower bound in a zone far behind
     * UTC: the last minute of today in Honolulu, against a job at the first
     * minute of yesterday there. `scheduled_for` is stored as that wall time,
     * so a bound set as a UTC instant two days back would cut it.
     */
    public function test_yesterday_counts_at_the_edge_of_the_window(): void
    {
        $tz = 'Pacific/Honolulu';
        $this->inTenant(fn () => $this->alpha['franchise']->forceFill(['timezone' => $tz])->save());
        Carbon::setTestNow(Carbon::parse('2026-10-05 23:59:00', $tz));
        $this->job($this->alpha['franchise'], ['scheduled_for' => Carbon::parse('2026-10-04 00:01:00', $tz)]);

        $figures = $this->payload()['data']['figures'];

        $this->assertSame(['key' => 'today', 'label' => 'today', 'value' => 0, 'previous' => 1], $figures[0]);
    }

    private function payload(): array
    {
        return app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
    }

    private function attention(): ?array
    {
        return $this->payload()['data']['attention'] ?? null;
    }

    /**
     * Money follows the role (Jose, 2026-10-05: "as for the money remember its
     * a role y permission things where ( can see financials ) should be
     * toggled"). Without jobs.financials the amount is nowhere in the payload,
     * under any key. The earned job is on the pie: the control.
     */
    public function test_no_money_reaches_a_reader_without_financials(): void
    {
        $this->earnedStatuses(['completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 987654, 'currency' => 'USD', 'status' => 'completed'], earned: true);
        $reader = $this->reader(['jobs.view']);

        $payload = app(JobsGlance::class)->resolve($reader, DashboardPeriod::for($reader, 'year'), [])->toArray();

        $this->assertContains(['label' => 'completed', 'total' => 1], $payload['data']['slices']);
        $json = json_encode($payload);
        foreach (['987654', '9876.54', 'amount_minor', 'USD', '"currency"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertNotContains('line', $payload['allowed_presentations']);
        $this->assertSame('pie', $payload['presentation']);
    }

    /**
     * The pair: with jobs.financials, what was earned in the period against
     * the same span before it, one figure per currency and never summed
     * across them, after the counts, and nowhere but the figures.
     */
    public function test_a_financials_reader_gets_earned_money_as_figures(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
        $this->earnedStatuses(['completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 987654, 'currency' => 'USD', 'status' => 'completed'], earned: true);
        $this->job($this->alpha['franchise'], ['amount_minor' => 5000, 'currency' => 'CAD', 'status' => 'completed'], earned: true);
        $yesterday = $this->job($this->alpha['franchise'], ['amount_minor' => 111, 'currency' => 'USD', 'status' => 'completed'], earned: true);
        $this->inTenant(fn () => $yesterday->forceFill(['earned_at' => Carbon::parse('2026-10-04 11:00:00', 'UTC')])->save());
        $this->job($this->alpha['franchise'], ['amount_minor' => 777, 'currency' => 'USD', 'status' => 'scheduled']);
        $reader = $this->reader(['jobs.view', 'jobs.financials']);

        $data = app(JobsGlance::class)->resolve($reader, DashboardPeriod::for($reader, 'day'), [])->toArray()['data'];

        $this->assertSame(['today', 'upcoming'], array_column(array_slice($data['figures'], 0, 2), 'key'));
        $this->assertSame([
            ['key' => 'earned_cad', 'label' => 'earned today', 'value' => 5000, 'previous' => 0, 'currency' => 'CAD'],
            ['key' => 'earned_usd', 'label' => 'earned today', 'value' => 987654, 'previous' => 111, 'currency' => 'USD'],
        ], array_slice($data['figures'], 2));
        $words = json_encode([$data['sentences'] ?? [], $data['notes'] ?? [], $data['attention'] ?? []]);
        $this->assertStringNotContainsString('987654', $words);
        $this->assertStringNotContainsString('9876.54', $words);
    }

    /**
     * The period is the reader's own day, in their franchise's zone, and
     * `earned_at` is an instant, so the bounds are converted rather than
     * compared as wall clocks. 22:00 last night in New York is 02:00 UTC
     * today, and it is not today's money.
     */
    public function test_earned_money_follows_the_readers_day(): void
    {
        $this->inTenant(fn () => $this->alpha['franchise']->forceFill(['timezone' => 'America/New_York'])->save());
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
        $this->earnedStatuses(['completed']);
        $lastNight = $this->job($this->alpha['franchise'], ['amount_minor' => 300, 'currency' => 'USD', 'status' => 'completed'], earned: true);
        $thisMorning = $this->job($this->alpha['franchise'], ['amount_minor' => 4000, 'currency' => 'USD', 'status' => 'completed'], earned: true);
        $this->inTenant(function () use ($lastNight, $thisMorning) {
            $lastNight->forceFill(['earned_at' => Carbon::parse('2026-10-05 02:00:00', 'UTC')])->save();
            $thisMorning->forceFill(['earned_at' => Carbon::parse('2026-10-05 10:00:00', 'UTC')])->save();
        });
        $reader = $this->reader(['jobs.view', 'jobs.financials']);

        $data = app(JobsGlance::class)->resolve($reader, DashboardPeriod::for($reader, 'day'), [])->toArray()['data'];

        $this->assertSame(
            [['key' => 'earned_usd', 'label' => 'earned today', 'value' => 4000, 'previous' => 0, 'currency' => 'USD']],
            array_slice($data['figures'], 2),
        );
    }

    /** A role holding exactly these permissions, reaching the first franchise. */
    private function reader(array $permissionKeys): User
    {
        return $this->inTenant(function () use ($permissionKeys) {
            $role = Role::create(['organization_id' => $this->alpha['org']->id, 'name' => 'Reader', 'is_system' => false]);
            $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Reader', 'email' => 'reader@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);
            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }
}
