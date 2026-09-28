<?php

namespace Tests\Feature\Automations;

use App\Mail\WorkHoursReportMail;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\TimeClockReport;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Automations\ActionRunner;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Send the work hours report" — Jose, 2026-09-28: the week's hours every
 * Sunday 8 PM Miami time to the dispatch manager.
 *
 * The mail sent is the artefact asserted, not the step's sentence. The
 * rule's author is the actor, so a rule whose author has gone sends nothing:
 * that case is also the control that the success case is not just "always ok".
 */
class SendWorkHoursReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Sunday 20:00 in New York, when the rule runs.
        Carbon::setTestNow(Carbon::parse('2026-09-28 00:00:00', 'UTC'));

        $this->tenant = $this->provisionTenant('Alpha');
        $this->inTenant($this->tenant['org'], function () {
            app(SettingsResolver::class)->set('time_clock.enabled', $this->tenant['org'], true);
            app(SettingsResolver::class)->set('timezone', $this->tenant['org'], 'America/New_York');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sendAs(User $author, array $action): array
    {
        return $this->inTenant($this->tenant['org'], function () use ($author, $action) {
            $org = $this->tenant['org']->id;

            TimeClockShift::query()->create(['organization_id' => $org, 'user_id' => $this->tenant['admin']->id,
                'clocked_in_at' => '2026-09-22 12:00:00', 'clocked_out_at' => '2026-09-22 20:30:00']);
            $report = TimeClockReport::query()->create(['organization_id' => $org, 'name' => 'Weekly hours',
                'recipients' => ['dismanager@a-1chimney.com']]);

            $automation = Automation::create(['organization_id' => $org, 'name' => 'Weekly hours',
                'trigger_key' => 'automation.schedule_reached', 'is_active' => true]);
            $version = AutomationVersion::create(['organization_id' => $org, 'automation_id' => $automation->id,
                'version' => 1, 'conditions' => null, 'actions' => [], 'created_by' => $author->id,
                'created_at' => now()]);
            $automation->forceFill(['active_version_id' => $version->id])->save();

            $event = AutomationEvent::create(['organization_id' => $org, 'event_key' => 'automation.schedule_reached',
                'payload' => [], 'occurred_at' => now(), 'available_at' => now()]);

            return app(ActionRunner::class)->run(['type' => 'send_report', 'report_id' => $report->id] + $action,
                $event, null, automation: $automation->fresh());
        });
    }

    public function test_a_rule_emails_the_report_to_its_saved_recipients(): void
    {
        Mail::fake();

        $result = $this->sendAs($this->tenant['admin'], []);

        $this->assertTrue($result['ok'], $result['detail']);
        Mail::assertSent(WorkHoursReportMail::class, fn ($m) => $m->hasTo('dismanager@a-1chimney.com'));
    }

    public function test_a_rule_can_send_it_somewhere_else(): void
    {
        Mail::fake();

        $result = $this->sendAs($this->tenant['admin'], ['to' => ['owner@example.test']]);

        $this->assertTrue($result['ok'], $result['detail']);
        Mail::assertSent(WorkHoursReportMail::class, fn ($m) => $m->hasTo('owner@example.test'));
        Mail::assertNotSent(WorkHoursReportMail::class, fn ($m) => $m->hasTo('dismanager@a-1chimney.com'));
    }

    public function test_a_rule_whose_author_has_gone_sends_nothing(): void
    {
        Mail::fake();
        $gone = $this->userWithRole($this->tenant, 'Former', ['work_hours.general.view']);
        $this->inTenant($this->tenant['org'], fn () => $gone->forceFill(['is_active' => false])->save());

        $result = $this->sendAs($gone, []);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no longer works here', $result['detail']);
        Mail::assertNothingSent();
    }
}
