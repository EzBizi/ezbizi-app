<?php

namespace Tests\Feature\Dashboard;

use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Calendar window for the dashboard glances (spec §6).
 *
 * One period for the whole page: week / month / quarter / year. Bounds live in
 * the current view's timezone, and the week bound reads week_starts_on rather
 * than assuming Monday.
 */
class DashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_month_starts_on_the_first_in_the_view_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 16:00:00', 'America/New_York'));
        // admin in Alpha, default timezone America/New_York
        $period = DashboardPeriod::for($this->alpha['admin'], 'month');
        $this->assertSame('month', $period->key);
        $this->assertSame('2026-08-01T00:00:00-04:00', $period->startsOn->toIso8601String());
        $this->assertSame('America/New_York', $period->timezone);
    }

    public function test_week_respects_week_starts_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'America/New_York')); // Wednesday
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('week_starts_on', $this->alpha['org'], 'monday'));
        $period = DashboardPeriod::for($this->alpha['admin'], 'week');
        $this->assertSame('2026-08-24', $period->startsOn->toDateString()); // Monday
    }
}
