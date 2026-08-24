<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\JobsGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jobs dashboard glance (spec § Jobs — pie, plus a revenue line).
 *
 * Pie of statuses under the caller's reach (snapshot), revenue as sentences
 * and a per-currency line over the dashboard period, plus today / upcoming
 * when something is scheduled.
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
                'role_id'         => $this->alpha['admin']->role_id,
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

    private function job(Franchise $franchise, array $attributes = []): Job
    {
        return $this->inTenant(fn () => Job::create($attributes + [
            'franchise_id' => $franchise->id,
            'customer_id'  => $this->customer($franchise)->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
        ]));
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
        $this->assertSame('No jobs in reach.', $payload['data']['sentence']);
    }

    public function test_revenue_is_not_added_across_currencies(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'CAD', 'status' => 'completed']);
        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin'], 'year'),
            ['earned_statuses' => ['completed']],
        )->toArray();
        $this->assertCount(2, $payload['data']['revenue']);
        $this->assertSame(['CAD', 'USD'], array_column($payload['data']['revenue'], 'currency'));
        $this->assertSame([20000, 10000], array_column($payload['data']['revenue'], 'amount_minor'));
        $this->assertCount(2, $payload['data']['sentences']);
    }

    public function test_unconfigured_revenue_asks_for_statuses(): void
    {
        $this->job($this->alpha['franchise']);
        $payload = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $this->assertContains(
            'Choose which of your job statuses count as earned.',
            $payload['data']['sentences'] ?? [],
        );
        $this->assertContains('scheduled', $payload['data']['status_choices'] ?? []);
        $this->assertContains('completed', $payload['data']['status_choices'] ?? []);
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

    public function test_earned_sentences_use_the_utc_period_start(): void
    {
        // View TZ is Etc/GMT+5 (UTC−5). Month starts 2026-08-01 00:00 there,
        // which is 05:00 UTC. A completed job at 02:00 UTC is still July and
        // must not count; one at 06:00 UTC is in the month.
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));
        $this->job($this->alpha['franchise'], [
            'amount_minor' => 10000,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 06:00:00', 'UTC'));
        $this->job($this->alpha['franchise'], [
            'amount_minor' => 2500,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-24 16:00:00', 'Etc/GMT+5'));

        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin'], 'month'),
            ['earned_statuses' => ['completed']],
        )->toArray();

        $this->assertSame([2500], array_column($payload['data']['revenue'] ?? [], 'amount_minor'));
        $this->assertContains('1 job earned in USD.', $payload['data']['sentences'] ?? []);
    }

    public function test_line_series_is_revenue_minor_units_per_currency(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'USD', 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 5000, 'currency' => 'CAD', 'status' => 'completed']);

        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin'], 'year'),
            ['earned_statuses' => ['completed']],
        )->toArray();

        $series = $payload['data']['series'] ?? [];
        $this->assertSame(['CAD', 'USD'], array_column($series, 'label'));

        $sums = [];
        foreach ($series as $row) {
            $sums[$row['label']] = array_sum(array_column($row['points'], 'value'));
        }
        $this->assertSame(30000, $sums['USD']);
        $this->assertSame(5000, $sums['CAD']);
    }

    public function test_allowed_presentations_include_line(): void
    {
        $this->job($this->alpha['franchise']);
        $payload = app(JobsGlance::class)->resolve(
            $this->alpha['admin'],
            DashboardPeriod::for($this->alpha['admin']),
            [],
        )->toArray();

        $this->assertContains('line', $payload['allowed_presentations']);
        $this->assertSame('pie', $payload['presentation']);
    }
}
