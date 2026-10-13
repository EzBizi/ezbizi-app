<?php

namespace Tests\Feature\Reports;

use App\Models\AutomationEvent;
use App\Models\CallReportLabel;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13 ("Yes", via Grok AI): calls AI read as booked with no Workiz job. */
class AiBookedNoJobTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_yesterdays_ai_booked_calls_without_a_job_are_raised_once_as_a_list(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 07:40', 'America/New_York'));
        config(['app.timezone' => 'America/New_York',
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                'remote_id' => $t['franchise']->id, 'display_name' => 'Texas']);
            $c->connect(['refresh_token' => 'x', 'api_token' => 'x']);
            $c->save();

            // Only the first caller has a job.
            WorkizJob::query()->create(['franchise_id' => $t['franchise']->id, 'connection_id' => $c->id,
                'remote_id' => 'JOB-1', 'booked_at' => '2026-10-06 10:00:00', 'synced_at' => now(),
                'payload' => ['UUID' => 'JOB-1', 'Phone' => '2145550001', 'CreatedDate' => '2026-10-06 10:00:00', 'Status' => 'Submitted']]);

            foreach (['has-job', 'no-job'] as $id) {
                CallReportLabel::query()->create(['call_id' => $id, 'provider' => 'grok', 'label' => 'booked']);
            }
        });

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/phone-number*' => Http::response(['records' => []]),
            '*/call-log*' => Http::response(['records' => [
                $this->rc('has-job', '+12145550001', '14:00'),
                $this->rc('no-job', '+12147948797', '17:55'),
            ], 'paging' => ['page' => 1]]),
        ]);

        $this->artisan('ezbizi:ai-booked-no-job', ['--organization' => $t['org']->id])
            ->expectsOutput('AI booked, no job: 1')
            ->assertSuccessful();

        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.ai_booked_no_job')->sole();
        $this->assertSame(1, $event->payload['after']['count'] ?? $event->payload['count'] ?? null);
        $summary = (string) ($event->payload['after']['summary'] ?? $event->payload['summary'] ?? '');
        $this->assertStringContainsString('1:55pm · A1 - New Client · +12147948797 · answered by Trey Brannan', $summary);
        $this->assertStringNotContainsString('2145550001', $summary);
    }

    private function rc(string $id, string $from, string $utc): array
    {
        return [
            'id' => $id, 'direction' => 'Inbound', 'result' => 'Accepted',
            'startTime' => "2026-10-06T{$utc}:00Z",
            'from' => ['phoneNumber' => $from, 'name' => 'CALLER'],
            'to' => ['phoneNumber' => '+15550009999'],
            'legs' => [
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'Main - IVR']],
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'A1 - New Client']],
                ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748'], 'duration' => 60],
            ],
        ];
    }
}
