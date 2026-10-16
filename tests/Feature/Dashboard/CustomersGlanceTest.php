<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\CustomersGlance;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Customers dashboard glance (spec § Customers — line).
 *
 * The in-reach book is the snapshot; new customers are the line. Empty only
 * when the book is empty. organization_total is the whole tenant book.
 */
class CustomersGlanceTest extends TestCase
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

    public function test_the_line_counts_only_customers_owned_in_reach(): void
    {
        [$second, $branch] = $this->secondBranch();
        $this->customer($this->alpha['franchise']);
        $this->customer($second);
        $payload = app(CustomersGlance::class)->resolve($branch, DashboardPeriod::for($branch, 'year'), [])->toArray();
        $sum = array_sum(array_column($payload['data']['series'][0]['points'], 'value'));
        $this->assertSame(1, $sum);
    }

    public function test_empty_period_is_a_sentence(): void
    {
        // Empty book: this org has no customers at all, not a quiet period.
        $payload = app(CustomersGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No new customers this week.', $payload['data']['sentence']);
    }

    public function test_a_quiet_period_still_shows_the_book(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise']);
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));

        // The axis below is hand-derived from a SUNDAY week start, which this
        // test until now inherited from the registry default rather than stated.
        // On 2026-09-22 this assertion failed ONCE, alone, with ['2026-08-24'] --
        // precisely the axis a MONDAY start draws -- and has not reproduced. It
        // is not a peer's test leaking: the only two tests that write
        // week_starts_on (DashboardPeriodTest, SettingsCascadeTest) both sort
        // AFTER this one, in the filtered run and the full suite alike. Pinning
        // the input costs a line and stops the expectation depending on a value
        // this test does not choose.
        //
        // One half is settled. A resolver returning nothing cannot draw a
        // Monday axis: DashboardPeriod::weekStartsAtFor ends in
        // `$startsOn === 'monday' ? MONDAY : SUNDAY`, so null, absent and the
        // registry default (SettingRegistry:212, 'sunday') all give Sunday.
        // Only the literal string 'monday' gives Monday. Confirmed by running
        // it: flipping this pin to 'monday' reds the assertion with exactly the
        // observed one-day shift, which also shows the pin is load-bearing.
        // So a real row said 'monday'.
        //
        // WHY THAT ROW EXISTED IS UNEXPLAINED. I previously wrote here that a
        // local run had probably read another session's schema; that is FALSE
        // and I am striking it rather than softening it. The failing run was
        // `DB_DATABASE=ezbizi_testing_gapi`, its own schema, never the shared
        // one, so no neighbour could reach it. Nothing else in the tree writes
        // this key -- `TimeClockWeekTest` writes `time_clock.week_starts_on`,
        // a different key -- `settings` holds no rows between runs, and
        // `Carbon::setWeekStartsAt`, the one process-global that would move
        // `startOfWeek()` with no row at all, appears nowhere in the repo.
        // A real row, in that process, written by nothing that exists.
        //
        // If it recurs, dump it BEFORE forming a theory, and without scopes:
        //   Setting::withoutGlobalScopes()
        //       ->where('key', 'week_starts_on')
        //       ->get(['scope_type', 'scope_id', 'value'])
        // A tenant-scoped read hides a row written under another organization,
        // which is the scope question actually worth answering here.
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('week_starts_on', $this->alpha['org'], 'sunday'));

        $payload = app(CustomersGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();

        $points = $payload['data']['series'][0]['points'];
        $sum = array_sum(array_column($points, 'value'));
        $this->assertFalse($payload['data']['empty']);
        $this->assertContains('1 customer listed.', $payload['data']['sentences']);
        $this->assertContains('0 new this week.', $payload['data']['sentences']);
        $this->assertSame(0, $sum);

        // The assertion the three above cannot make. A dropped bucket carries
        // the identity value, so the sum stays 0 and the payload stays
        // non-empty whether this week drew every day or only the one the
        // customer landed in -- the case the docblock promises to draw,
        // "zeros included so the line does not skip quiet days", is exactly the
        // case those cannot distinguish.
        //
        // Axis keys rather than a count, because the count alone would not say
        // the axis STARTS at the week's start. The clock is pinned to Monday
        // 2026-08-24 and the week begins Sunday the 23rd, so a correct series
        // is those two days and no others -- the 23rd being a day with nothing
        // on it, which is the whole point. NOT seven: this period runs from the
        // week's start to NOW, not to the week's end (`DashboardPeriod::for`
        // takes `endsOn` from `$now`), so seven columns would be right only
        // when the week is over.
        $this->assertSame(['2026-08-23', '2026-08-24'], array_column($points, 'at'));
        $this->assertSame([0, 0], array_column($points, 'value'));
    }

    public function test_organization_total_is_the_whole_book(): void
    {
        [$second, $branch] = $this->secondBranch();

        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise']);
        $this->customer($this->alpha['franchise']);

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->customer($second);

        $payload = app(CustomersGlance::class)->resolve(
            $branch, DashboardPeriod::for($branch, 'week'), []
        )->toArray();
        $sum = array_sum(array_column($payload['data']['series'][0]['points'], 'value'));

        $this->assertSame(3, $payload['data']['organization_total']);
        $this->assertSame(1, $sum);
    }

    public function test_an_imported_customer_counts_on_the_day_they_became_one(): void
    {
        // The import wrote this row today, but the client started in 2019.
        // "New this week" has to say 0 for them -- the dashboard read 36,067
        // new customers in one week before this, all of them the A1 import.
        //
        // TWO of them, deliberately. With one, the old created_at-only count
        // and the right count both came to 2 -- it trades the imported row in
        // for the started-this-week one -- and this test passed against the
        // very bug it names. Measured: it did, before the second was added.
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        foreach (['Imported', 'Imported too'] as $name) {
            $imported = $this->customer($this->alpha['franchise'], $name);
            $this->inTenant(fn () => Customer::query()->whereKey($imported->id)
                ->update(['customer_since' => '2019-05-02']));
        }

        // And the reverse, so a fix that simply ignored created_at cannot pass:
        // made today with no customer_since is genuinely new this week.
        $this->customer($this->alpha['franchise'], 'Typed today');

        // And one that BECAME a customer this week but was imported earlier.
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'America/New_York'));
        $recent = $this->customer($this->alpha['franchise'], 'Started this week');
        $this->inTenant(fn () => Customer::query()->whereKey($recent->id)
            ->update(['customer_since' => '2026-08-24']));

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('week_starts_on', $this->alpha['org'], 'sunday'));

        $payload = app(CustomersGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();

        $this->assertContains('2 new this week.', $payload['data']['sentences']);
    }

    /**
     * The headline figure for the sized dashboard: new today against
     * yesterday TO THE SAME TIME. The 13:00 row yesterday is after "now"
     * (12:00), so a whole-day comparison would say 3 and this says 2.
     */
    public function test_the_new_figure_compares_with_the_same_span_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 10:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise'], 'Yesterday morning');
        Carbon::setTestNow(Carbon::parse('2026-08-23 13:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise'], 'Yesterday afternoon');

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $imported = $this->customer($this->alpha['franchise'], 'Became one yesterday');
        $this->inTenant(fn () => Customer::query()->whereKey($imported->id)
            ->update(['customer_since' => '2026-08-23']));
        foreach (['A', 'B', 'C'] as $name) {
            $this->customer($this->alpha['franchise'], $name);
        }

        $payload = app(CustomersGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'day'), []
        )->toArray();

        $this->assertSame([
            ['key' => 'new', 'label' => 'new today', 'value' => 3, 'previous' => 2],
            ['key' => 'listed', 'label' => 'listed', 'value' => 6, 'previous' => null],
        ], $payload['data']['figures']);
    }

    public function test_changing_period_changes_the_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise']); // created now
        $week = app(CustomersGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray();
        $this->assertFalse($week['data']['empty']);
    }

    public function test_the_bar_gets_slices_from_the_same_buckets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->customer($this->alpha['franchise']);
        $payload = app(CustomersGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $points = $payload['data']['series'][0]['points'];
        $slices = $payload['data']['slices'] ?? [];

        $this->assertNotEmpty($slices);
        $this->assertSame(array_column($points, 'at'), array_column($slices, 'label'));
        $this->assertSame(array_column($points, 'value'), array_column($slices, 'total'));
        $this->assertContains('2026-08', array_column($slices, 'label'));
    }
}
