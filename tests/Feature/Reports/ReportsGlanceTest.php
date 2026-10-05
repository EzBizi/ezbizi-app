<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\SavedReport;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\ReportsGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Reports box flags a scheduled report that stopped (Dashboard, 2026-10-05:
 * "the rows that need somebody").
 */
class ReportsGlanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_report_whose_automation_switched_itself_off_needs_somebody(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $stopped = $this->report($tenant, 'Stopped');
        $this->rule($tenant, 'Gave up', $stopped, disabled: true);

        // Still sent by a second rule: not stopped, not counted.
        $covered = $this->report($tenant, 'Covered');
        $this->rule($tenant, 'Gave up too', $covered, disabled: true);
        $this->rule($tenant, 'Still going', $covered, disabled: false);

        $data = $this->glance($tenant);

        $this->assertSame(
            [['tone' => 'bad', 'text' => '1 scheduled report stopped sending: its automation switched itself off.']],
            $data['attention'],
        );

        // Counted, never named: the rule is Automations' data.
        $this->assertStringNotContainsString('Gave up', json_encode($data));
    }

    public function test_nothing_needs_anybody_when_every_rule_runs(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->rule($tenant, 'Fine', $this->report($tenant, 'Fine'), disabled: false);

        $data = $this->glance($tenant);

        $this->assertFalse($data['empty']);
        $this->assertArrayNotHasKey('attention', $data);
    }

    private function glance(array $tenant): array
    {
        $user = $tenant['admin']->fresh();

        return app(ReportsGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray()['data'];
    }

    private function report(array $tenant, string $name): string
    {
        return $this->inTenant($tenant['org'], fn () => SavedReport::query()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'definition' => ['root' => 'jobs', 'columns' => [['resource' => 'jobs', 'column' => 'title']], 'filters' => []],
        ])->id);
    }

    /** Shaped like Automations' own ReportSendersTest fixture. */
    private function rule(array $tenant, string $name, string $reportId, bool $disabled): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $name, $reportId, $disabled) {
            $org = $tenant['org']->id;
            $rule = Automation::create(['organization_id' => $org, 'name' => $name,
                'trigger_key' => 'automation.schedule_reached', 'is_active' => ! $disabled,
                'schedule' => ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00', 'timezone' => 'America/New_York']]);
            $version = AutomationVersion::create(['organization_id' => $org, 'automation_id' => $rule->id, 'version' => 1,
                'conditions' => null, 'actions' => [['type' => 'report.send', 'report_id' => $reportId]], 'created_at' => now()]);
            $rule->forceFill(['active_version_id' => $version->id, 'disabled_at' => $disabled ? now() : null])->save();
        });
    }
}
