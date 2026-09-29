<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Support\Automations\ReportSenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which rules send a report: the ACTIVE version only, the type named, and a
 * switched-off rule kept with its flag. Each case has its near miss beside
 * it: an older version, the other action type, another report, another
 * organization.
 */
class ReportSendersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function rule(array $tenant, string $name, array $activeActions, array $olderActions = [], bool $active = true): Automation
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $activeActions, $olderActions, $active) {
            $org = $tenant['org']->id;
            $rule = Automation::create(['organization_id' => $org, 'name' => $name,
                'trigger_key' => 'automation.schedule_reached', 'is_active' => $active,
                'schedule' => ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00', 'timezone' => 'America/New_York']]);
            $n = 1;
            if ($olderActions !== []) {
                AutomationVersion::create(['organization_id' => $org, 'automation_id' => $rule->id, 'version' => $n++,
                    'conditions' => null, 'actions' => $olderActions, 'created_at' => now()]);
            }
            $v = AutomationVersion::create(['organization_id' => $org, 'automation_id' => $rule->id, 'version' => $n,
                'conditions' => null, 'actions' => $activeActions, 'created_at' => now()]);
            $rule->forceFill(['active_version_id' => $v->id])->save();

            return $rule;
        });
    }

    public function test_it_names_the_rules_whose_active_version_sends_the_report(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $sends = $this->rule($alpha, 'Sunday hours', [['type' => 'send_report', 'report_id' => 'r-1']]);
        $off = $this->rule($alpha, 'Paused', [['type' => 'send_report', 'report_id' => 'r-1']], active: false);
        $this->rule($alpha, 'Used to', [['type' => 'notify', 'message' => 'x']], [['type' => 'send_report', 'report_id' => 'r-1']]);
        $this->rule($alpha, 'Other type', [['type' => 'report.send', 'report_id' => 'r-1']]);
        $this->rule($alpha, 'Other report', [['type' => 'send_report', 'report_id' => 'r-2']]);
        $this->rule($beta, 'Elsewhere', [['type' => 'send_report', 'report_id' => 'r-1']]);

        $found = $this->inTenant($alpha['org'], fn () => ReportSenders::for(ReportSenders::REPORT_WORK_HOURS, ['r-1', 'r-3']));

        $this->assertSame(['r-1'], array_keys($found), 'a report no rule sends is absent');
        $this->assertEqualsCanonicalizing([$sends->id, $off->id], array_column($found['r-1'], 'id'));

        $paused = collect($found['r-1'])->firstWhere('id', $off->id);
        $this->assertFalse($paused['is_active'], 'kept, and said to be off');
        $this->assertSame('20:00', $paused['schedule']['time']);
    }
}
