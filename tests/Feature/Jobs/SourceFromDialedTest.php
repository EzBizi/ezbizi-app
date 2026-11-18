<?php

namespace Tests\Feature\Jobs;

use App\Models\Automation;
use App\Models\BusinessListItem;
use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Support\Automations\ActionRunner;
use App\Models\AutomationEvent;
use App\Support\Jobs\SourceFromDialed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-16: a job's source from the line its client dialed, in EzBizi and Workiz. */
class SourceFromDialedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_line_matches_by_number_or_by_the_end_of_its_name(): void
    {
        $rules = [['key' => '- PPC', 'value' => ['line' => '- PPC', 'source' => 'PPC']],
            ['key' => '7324264555', 'value' => ['line' => '(732) 426-4555', 'source' => 'Google PPC']]];

        $this->assertSame('PPC', SourceFromDialed::sourceFor($rules, '+18562908555', 'NJ - PPC'));
        $this->assertSame('Google PPC', SourceFromDialed::sourceFor($rules, '+17324264555', 'NJ - LS Ads'));
        $this->assertNull(SourceFromDialed::sourceFor($rules, '+12125550000', 'NJ - LS Ads'));
        $this->assertNull(SourceFromDialed::sourceFor($rules, '+12125550000', null));
    }

    public function test_a_job_from_a_listed_line_gets_its_source_in_workiz_and_ezbizi_going_forward_only(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $written = [];
        $this->app->bind(SourceFromDialed::class, function ($app) use (&$written) { return new class($app->make(\App\Support\Jobs\DialedLines::class), $app->make(\App\Support\Settings\Lists\BusinessLists::class), $app->make(\App\Support\Audit\AuditLogger::class), $written) extends SourceFromDialed
        {
            public function __construct($lines, $lists, $audit, private array &$written)
            {
                parent::__construct($lines, $lists, $audit);
            }

            protected function writeToWorkiz(WorkizJob $mirror, string $source): void
            {
                if ($mirror->remote_id === 'J05') {
                    throw new RuntimeException('Could not find matching value for JobSource');
                }
                $this->written[] = $mirror->remote_id.'='.$source;
            }
        }; });

        $result = $this->inTenant($t['org'], function () use ($t, $f) {
            $rule = Automation::create(['name' => 'Source', 'trigger_key' => 'schedule.tick', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $rule->forceFill(['created_at' => now()->subHour()])->save();
            BusinessListItem::create(['organization_id' => $t['org']->id, 'list_key' => 'jobs.sources_by_line',
                'item_key' => '7324264555', 'value' => ['line' => '(732) 426-4555', 'source' => 'PPC'], 'sort' => 0, 'active' => true]);
            $ezbizi = Job::factory()->create(['franchise_id' => $f->id, 'job_source' => 'LS Ads',
                'customer_id' => Customer::factory()->create(['owning_franchise_id' => $f->id])->id]);
            $job = fn (string $ref, string $booked, string $source = 'LS Ads', array $more = []) => WorkizJob::query()->create([
                'franchise_id' => $f->id, 'remote_id' => $ref, 'serial_id' => '6000'.substr($ref, -2), 'job_source' => $source,
                'status' => 'Submitted', 'booked_at' => $booked, 'payload' => ['Phone' => '(212) 555-0101', 'v2' => ['id' => 'JOB-'.$ref]],
                'synced_at' => now(),
            ] + $more);
            $call = fn (string $ref, string $to) => DB::table('workiz_calls')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f->organization_id,
                'franchise_id' => $f->id, 'remote_id' => 'CAL-'.$ref, 'direction' => 'inbound', 'from_number' => '+12125550101', 'to_number' => $to,
                'job_remote_id' => 'JOB-'.$ref, 'called_at' => now()->toDateTimeString(), 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            $job('J01', now()->toDateTimeString(), more: ['job_id' => $ezbizi->id]);
            $call('J01', '+17324264555');
            $job('J02', now()->subDays(1)->toDateTimeString());          // before the rule: left alone
            $call('J02', '+17324264555');
            $job('J03', now()->toDateTimeString(), 'PPC');              // already right
            $call('J03', '+17324264555');
            $job('J04', now()->toDateTimeString());                     // a line not listed
            $call('J04', '+12125550000');
            $job('J05', now()->toDateTimeString());                     // Workiz refuses it
            $call('J05', '+17324264555');

            return [app(ActionRunner::class)->run(['type' => 'job.source_from_dialed'], new AutomationEvent(['event_key' => 'schedule.tick', 'payload' => []]), null, automation: $rule), $ezbizi];
        });
        [$done, $ezbizi] = $result;

        $this->assertSame(['J01=PPC'], $written, 'only the listed line booked after the rule, not already right');
        $this->assertFalse($done['ok'], 'a refusal is a failed step');
        $this->assertStringContainsString('Changed the source of 1 job.', $done['detail']);
        $this->assertStringContainsString('Could not find matching value', $done['detail']);
        $this->inTenant($t['org'], function () use ($ezbizi) {
            $this->assertSame('PPC', $ezbizi->fresh()->job_source);
            $logged = DB::table('audit_events')->where('action', 'job.source_changed')->sole();
            $this->assertStringContainsString('dialed +17324264555', (string) $logged->metadata);
            $this->assertStringContainsString('LS Ads', (string) $logged->changes);
        });
    }

    /** Desk for Jose, 2026-11-17: Reports are read during the day, so active rules also run every 15 minutes. */
    public function test_the_15_minute_command_runs_each_active_rule(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $written = [];
        $this->app->bind(SourceFromDialed::class, function ($app) use (&$written) { return new class($app->make(\App\Support\Jobs\DialedLines::class), $app->make(\App\Support\Settings\Lists\BusinessLists::class), $app->make(\App\Support\Audit\AuditLogger::class), $written) extends SourceFromDialed
        {
            public function __construct($lines, $lists, $audit, private array &$written)
            {
                parent::__construct($lines, $lists, $audit);
            }

            protected function writeToWorkiz(WorkizJob $mirror, string $source): void
            {
                $this->written[] = $mirror->remote_id.'='.$source;
            }
        }; });
        $this->inTenant($t['org'], function () use ($t, $f) {
            $rule = Automation::create(['name' => 'Source', 'trigger_key' => 'automation.schedule_reached', 'is_active' => true, 'created_by' => $t['admin']->id]);
            $version = $rule->versions()->create(['organization_id' => $t['org']->id, 'version' => 1, 'actions' => [['type' => 'job.source_from_dialed']]]);
            $rule->forceFill(['active_version_id' => $version->id, 'created_at' => now()->subHour()])->save();
            BusinessListItem::create(['organization_id' => $t['org']->id, 'list_key' => 'jobs.sources_by_line',
                'item_key' => '7324264555', 'value' => ['line' => '(732) 426-4555', 'source' => 'PPC'], 'sort' => 0, 'active' => true]);
            WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'J01', 'serial_id' => '600001', 'job_source' => 'LS Ads',
                'status' => 'Submitted', 'booked_at' => now()->toDateTimeString(), 'payload' => ['Phone' => '(212) 555-0101', 'v2' => ['id' => 'JOB-J01']], 'synced_at' => now()]);
            DB::table('workiz_calls')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f->organization_id, 'franchise_id' => $f->id,
                'remote_id' => 'CAL-J01', 'direction' => 'inbound', 'from_number' => '+12125550101', 'to_number' => '+17324264555',
                'job_remote_id' => 'JOB-J01', 'called_at' => now()->toDateTimeString(), 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->artisan('ezbizi:jobs-source-from-dialed')->assertSuccessful();

        $this->assertSame(['J01=PPC'], $written);
    }
}
