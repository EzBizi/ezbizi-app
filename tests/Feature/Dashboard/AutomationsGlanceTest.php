<?php

namespace Tests\Feature\Dashboard;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\AutomationsGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Automations dashboard glance (spec § Automations — pie).
 *
 * Snapshot of succeeded vs failed among the last 100 runs. Idle (no runs) is a
 * sentence, not 0/0 as health. Waiting undelivered events ride as a sentence.
 */
class AutomationsGlanceTest extends TestCase
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

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function runWithStatus(string $status): void
    {
        $this->inTenant(function () use ($status) {
            $event = new AutomationEvent;
            $event->forceFill([
                'organization_id' => $this->alpha['org']->id,
                'event_key' => 'job.created',
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
                'delivered_at' => now(),
            ])->save();

            $automation = Automation::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'A rule',
                'trigger_key' => 'job.created',
                'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $this->alpha['org']->id,
                'automation_id' => $automation->getKey(),
                'version' => 1,
                'trigger_key' => 'job.created',
                'conditions' => [],
                'actions' => [],
            ]);

            AutomationRun::create([
                'organization_id' => $this->alpha['org']->id,
                'automation_id' => $automation->getKey(),
                'automation_version_id' => $version->getKey(),
                'event_id' => $event->getKey(),
                'status' => $status,
                'action_count' => 0,
                'started_at' => now(),
            ]);
        });
    }

    private function stoppedRule(string $name): Automation
    {
        return $this->inTenant(function () use ($name) {
            $rule = Automation::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => $name,
                'trigger_key' => 'job.created',
                'is_active' => false,
            ]);

            $rule->forceFill([
                'disabled_at' => now(),
                'disabled_reason' => 'Disabled after 5 consecutive failures.',
            ])->save();

            return $rule;
        });
    }

    private function sentences(): array
    {
        $data = app(AutomationsGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data'];

        return ($data['empty'] ?? false) ? [$data['sentence']] : ($data['sentences'] ?? []);
    }

    /**
     * A stopped rule is a CONDITION, so the box says so for as long as it is off.
     *
     * The failure this guards is not the sentence being wrong, it is the sentence
     * never appearing on the branch nobody looks at: an organization whose only
     * automation stopped has no runs, so it takes the EMPTY branch, where the
     * client renders `sentence` alone and drops `sentences` entirely.
     */
    public function test_a_stopped_rule_is_said_on_the_empty_branch_too(): void
    {
        $this->stoppedRule('Chase completions');

        $this->assertSame(
            ["No automations have run. 'Chase completions' stopped itself and is still off."],
            $this->sentences(),
        );
    }

    public function test_a_stopped_rule_is_said_alongside_runs(): void
    {
        $this->runWithStatus(AutomationRun::SUCCEEDED);
        $this->stoppedRule('Chase completions');

        $this->assertContains(
            "'Chase completions' stopped itself and is still off.",
            $this->sentences(),
        );
    }

    /**
     * Turning it back on clears it, with nothing to dismiss.
     *
     * This is the assertion that makes the box a condition rather than an alarm.
     * If it fails, the sentence is derived from something that does not clear and
     * the dashboard carries a permanent false alarm.
     */
    public function test_the_sentence_clears_itself_when_the_rule_is_turned_back_on(): void
    {
        $rule = $this->stoppedRule('Chase completions');

        $this->assertNotSame([], $this->sentences(), 'nothing to clear, so this proves nothing');

        $this->inTenant(fn () => $rule->forceFill([
            'is_active' => true, 'disabled_at' => null, 'disabled_reason' => null,
        ])->save());

        // Not assertSame([]) - with no runs the box is still on its empty branch
        // and still says so. What must be gone is the stopped clause.
        $this->assertSame(['No automations have run.'], $this->sentences());
    }

    public function test_idle_is_a_sentence_not_zero_over_zero(): void
    {
        $payload = app(AutomationsGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No automations have run.', $payload['data']['sentence']);
    }

    public function test_the_pie_ignores_the_dashboard_period(): void
    {
        $this->runWithStatus(AutomationRun::SUCCEEDED);
        $this->runWithStatus(AutomationRun::FAILED);

        $week = app(AutomationsGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $year = app(AutomationsGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }
}
