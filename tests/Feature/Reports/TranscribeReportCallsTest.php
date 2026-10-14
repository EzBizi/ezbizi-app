<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportTranscript;
use App\Models\Lead;
use App\Models\LeadConversation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Experimental report's transcripts: reused from a Local Services lead
 * call when the same call was transcribed there, otherwise left to Whisper.
 */
class TranscribeReportCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These calls are dated before AI's start day (2026-10-13); move it back.
        config(['services.grok_cli.since' => '2026-09-01']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_call_already_transcribed_as_a_lead_reuses_those_words(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 15:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York', 'services.whisper.python' => '', 'experimental_reports.transcribe' => true]);

        $tenant = $this->provisionTenant('Alpha');
        $this->leadCall($tenant, '+1 (212) 555-1212', '2026-10-05T17:01:30Z', 'Hi, I need a chimney sweep.');
        $this->ringCentral([
            $this->rcCall('rc-1', '+12125551212', '2026-10-05T17:00:00Z', 'A1 - New Client'),      // matches, 90s apart
            $this->rcCall('rc-2', '+12125550000', '2026-10-05T17:05:00Z', 'A1 - Existing Client'), // no lead, no Whisper here
            $this->rcCall('rc-3', '+12125559999', '2026-10-05T17:06:00Z', 'Ace Fireplace'),        // not a panel's queue
        ]);

        $this->artisan('ezbizi:transcribe-report-calls', ['--organization' => $tenant['org']->id, '--days' => 1])
            ->assertSuccessful();

        $rows = CallReportTranscript::withoutGlobalScopes()->get()->keyBy('call_id');

        $this->assertSame('lead', $rows['rc-1']->source);
        $this->assertSame('Hi, I need a chimney sweep.', $rows['rc-1']->transcript);

        // Who took it and when travel with the words, for per-person scoring.
        $this->assertSame('Trey Brannan', $rows['rc-1']->answered_by);
        $this->assertSame('2026-10-05 17:00:00', $rows['rc-1']->call_started_at?->utc()->format('Y-m-d H:i:s'));

        // Whisper is not installed in tests: marked failed, so it is not retried for ever.
        $this->assertSame('failed', $rows['rc-2']->source);
        $this->assertNull($rows['rc-2']->transcript);

        // Every line, not only New and Existing (Jose, 2026-10-14).
        $this->assertSame('failed', $rows['rc-3']->source);
    }

    /** A 6-minute call with 219 characters from Local Services was another leg's words (2026-10-14). */
    public function test_a_lead_transcript_too_short_for_the_call_is_not_used(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 15:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York', 'services.whisper.python' => '', 'experimental_reports.transcribe' => true]);

        $tenant = $this->provisionTenant('Alpha');
        $this->leadCall($tenant, '+1 (212) 555-1212', '2026-10-05T17:01:30Z', 'Hi, I need a chimney sweep.');
        $this->ringCentral([$this->rcCall('rc-1', '+12125551212', '2026-10-05T17:00:00Z', 'A1 - New Client', 377)]);

        $this->artisan('ezbizi:transcribe-report-calls', ['--organization' => $tenant['org']->id, '--days' => 1])
            ->assertSuccessful();

        $this->assertSame('failed', CallReportTranscript::withoutGlobalScopes()->sole()->source, 'left to Whisper');
    }

    /** Off by default: nothing is read or written until it is switched on. */
    public function test_it_does_nothing_while_switched_off(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->ringCentral([$this->rcCall('rc-1', '+12125551212', '2026-10-05T17:00:00Z', 'A1 - New Client')]);

        $this->artisan('ezbizi:transcribe-report-calls', ['--organization' => $tenant['org']->id])
            ->assertSuccessful();

        $this->assertSame(0, CallReportTranscript::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_the_report_carries_each_calls_words(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');
        $this->ringCentral([$this->rcCall('rc-1', '+12125551212', '2026-09-14T17:00:00Z', 'A1 - New Client')]);

        $this->inTenant($tenant['org'], fn () => CallReportTranscript::query()->create([
            'organization_id' => $tenant['org']->id, 'call_id' => 'rc-1', 'transcript' => 'Price was too high.', 'source' => 'whisper',
        ]));

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->assertJsonPath('calls.0.transcript', 'Price was too high.');
    }

    private function leadCall(array $tenant, string $phone, string $at, string $words): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $phone, $at, $words) {
            $lead = Lead::create([
                'organization_id' => $tenant['org']->id,
                'source' => 'local_services',
                'remote_id' => 'lsa-1',
                'lead_type' => 'PHONE_CALL',
                'phone' => $phone,
                'received_at' => CarbonImmutable::parse($at),
            ]);

            LeadConversation::create([
                'organization_id' => $tenant['org']->id,
                'lead_id' => $lead->id,
                'remote_id' => 'lsa-1-call',
                'channel' => 'PHONE_CALL',
                'participant' => 'CONSUMER',
                'occurred_at' => CarbonImmutable::parse($at),
                'transcript' => $words,
            ]);
        });
    }

    private function rcCall(string $id, string $from, string $at, string $queue, int $talk = 60): array
    {
        return [
            'id' => $id,
            'direction' => 'Inbound',
            'result' => 'Accepted',
            'startTime' => $at,
            'from' => ['phoneNumber' => $from, 'name' => 'CALLER'],
            'to' => ['name' => 'Main - IVR'],
            'recording' => ['id' => "rec-{$id}"],
            'legs' => [
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'Main - IVR']],
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => $queue]],
                ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748'], 'duration' => $talk],
            ],
        ];
    }

    private function ringCentral(array $records): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => $records, 'paging' => ['page' => 1]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);
    }
}
