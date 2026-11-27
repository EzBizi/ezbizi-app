<?php

namespace Tests\Feature\Workiz;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose via Inspection, 2026-10-06: "inspection reports should also go up in
 * workiz when done via the app". No Workiz API takes a file, so the report's
 * link goes up: one line at the end of the Workiz job's description.
 */
class InspectionReportToWorkizTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function job(array $overrides = []): array
    {
        return array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'], $overrides);
    }

    /** A submitted report on a job, mirrored from a branch on its v2 key unless $workiz is false. */
    private function reportFor(array $tenant, bool $workiz = true): InspectionReport
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $workiz) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-1', 'name' => 'Dana Okafor']);
            $job = Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Annual inspection', 'status' => 'scheduled']);

            if ($workiz) {
                $c = new SocialConnection;
                $c->forceFill([
                    'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                    'scope_id' => $tenant['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                    'remote_id' => $tenant['franchise']->id, 'display_name' => 'Connecticut',
                ]);
                $c->connect(['api_token' => 'v2key_CT', 'refresh_token' => 'v2key_CT', 'api_version' => 'v2']);
                $c->save();
                WorkizJob::land($c, WorkizV2::job($this->job()));
                WorkizJob::query()->update(['job_id' => $job->id]);
            }

            $screens = [['name' => 'Findings', 'fields' => [['key' => 'notes', 'label' => 'Notes', 'type' => 'text']]]];
            $definition = InspectionDefinition::create(['name' => 'Level 1 Chimney Inspection', 'created_by' => $tenant['admin']->id, 'screens' => $screens]);
            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id, 'version' => 1, 'layout_preset' => 'step_strip',
                'screens' => $screens, 'published_by' => $tenant['admin']->id,
            ]);

            return InspectionReport::create([
                'customer_id' => $customer->id, 'job_id' => $job->id, 'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $version->id, 'submitted_by' => $tenant['admin']->id,
                'submitted_at' => '2026-10-06 15:00:00', 'answers' => ['notes' => 'All clear.'],
                'layout_preset' => 'step_strip', 'definition_name' => 'Level 1 Chimney Inspection',
            ]);
        });
    }

    /** @return array{type: string, ok: bool, detail: string} */
    private function act(array $tenant, InspectionReport $report): array
    {
        return $this->inTenant($tenant['org'], fn () => app(ActionRunner::class)->run(
            ['type' => 'inspection_report.workiz'],
            AutomationEvent::create(['event_key' => 'inspection.submitted', 'subject_type' => 'inspection_report', 'subject_id' => $report->id,
                'payload' => [], 'occurred_at' => now(), 'available_at' => now()]),
            subject: $report->fresh(),
        ));
    }

    /** One line, added once: a retried run finds its link already there and writes nothing. */
    public function test_the_report_link_goes_up_to_the_workiz_job_once(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $report = $this->reportFor($tenant);
        $line = 'Inspection report 10/06/2026: '.$report->shareUrl();
        // ONE fake: a second Http::fake stacks behind the first and never answers.
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::sequence()
            ->push(['data' => [$this->job(['description' => 'Swept, all good.'])]])
            ->push($this->job(['description' => "Swept, all good.\n".$line]))
            ->push(['data' => [$this->job(['description' => "Swept, all good.\n".$line])]])]);

        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\AttachFileToWorkiz::class]);
        \Illuminate\Support\Facades\Storage::fake('local');

        $first = $this->act($tenant, $report);
        $second = $this->act($tenant, $report);

        $this->assertSame([true, 'The report link was added to the Workiz job, and the PDF is being attached.'], [$first['ok'], $first['detail']]);
        // The PDF follows the link, once: the retry wrote no link, so it attaches nothing.
        \Illuminate\Support\Facades\Queue::assertPushedTimes(\App\Jobs\AttachFileToWorkiz::class, 1);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('inspection-reports/'.$report->organization_id.'/'.$report->id.'.pdf');
        $this->assertSame([true, 'The report was already on the Workiz job.'], [$second['ok'], $second['detail']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['description'] === "Swept, all good.\n".$line && $r->hasHeader('Authorization', 'Bearer v2key_CT'));
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT')->count());
    }

    /** On a job Workiz does not have, or on a client alone, it is left alone -- though other jobs ARE in Workiz. */
    public function test_an_inspection_on_no_workiz_job_is_left_alone(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->reportFor($tenant);
        $report = $this->inTenant($tenant['org'], function () use ($tenant, $mirrored) {
            $job = Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $mirrored->customer_id, 'number' => 'J-2', 'title' => 'Another', 'status' => 'scheduled']);

            return [
                $mirrored->replicate()->fill(['job_id' => $job->id]),
                $mirrored->replicate()->fill(['job_id' => null]),
            ];
        });
        Http::fake();

        foreach ($report as $unlinked) {
            $this->inTenant($tenant['org'], fn () => $unlinked->save());
            $result = $this->act($tenant, $unlinked);
            $this->assertSame([false, 'This inspection is not on a Workiz job, so there was nothing to put it on.'], [$result['ok'], $result['detail']]);
        }

        Http::assertNothingSent();
    }

    public function test_a_refusal_is_reported_and_nothing_is_claimed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $report = $this->reportFor($tenant);
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::sequence()
            ->push(['data' => [$this->job(['description' => ''])]])
            ->push(['message' => 'Too Many Requests', 'statusCode' => 429], 429)]);

        $result = $this->act($tenant, $report);

        $this->assertSame([false, 'Workiz did not take it: Too Many Requests'], [$result['ok'], $result['detail']]);
    }

    public function test_the_action_is_offered_to_the_builder_as_implemented(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Manager', [
            'automations.view', 'automations.build.view', 'inspection.view', 'inspection.new.view',
            'inspection.reports.view', 'inspection.builder.view',
        ]);

        $actions = collect($this->signedInAs($user)->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.modules'))
            ->flatMap(fn ($module) => $module['declared_actions'] ?? [])->keyBy('key');

        $this->assertTrue($actions['inspection.report.workiz']['implemented'] ?? false);
    }

    /**
     * The rule the command made, run by the engine on the event a submission
     * emits: A1-Group had no inspection report yet to watch it on
     * (2026-10-06), and the other tests hand the action its subject directly.
     */
    public function test_a_submitted_inspection_reaches_workiz_through_the_rule(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $report = $this->reportFor($tenant);
        $line = 'Inspection report 10/06/2026: '.$report->shareUrl();
        $this->artisan('ezbizi:workiz-inspection-rule', ['organization' => $tenant['org']->id, 'by' => $tenant['admin']->id])->assertSuccessful();
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::sequence()
            ->push(['data' => [$this->job(['description' => ''])]])
            ->push($this->job(['description' => $line]))]);

        $this->inTenant($tenant['org'], function () use ($report) {
            $event = app(EventEmitter::class)->emit('inspection.submitted', $report, ['report_id' => $report->id]);
            app(AutomationEngine::class)->deliver($event);
        });

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['description'] === $line);
    }

    /** Jose asked for it for A1-Group: one active rule, made once. */
    public function test_the_rule_is_created_once_and_switched_on(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->artisan('ezbizi:workiz-inspection-rule', ['organization' => $tenant['org']->id, 'by' => $tenant['admin']->id])->assertSuccessful();
        $this->artisan('ezbizi:workiz-inspection-rule', ['organization' => $tenant['org']->id, 'by' => $tenant['admin']->id])
            ->expectsOutputToContain('already has the rule')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $rule = Automation::query()->sole();
            $this->assertSame(['inspection.submitted', true], [$rule->trigger_key, $rule->is_active]);
            $this->assertSame([['type' => 'inspection_report.workiz']], $rule->activeVersion->actions);
        });
    }
}
