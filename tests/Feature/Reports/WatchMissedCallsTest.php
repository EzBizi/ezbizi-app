<?php

namespace Tests\Feature\Reports;

use App\Models\AutomationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Bar, 2026-10-07: a notification for every missed call in the report. */
class WatchMissedCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_new_missed_or_voicemail_call_is_raised_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 15:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => [
                $this->rc('missed', 'Missed', 'A1 - New Client', '18:55'),
                $this->rc('vm', 'Voicemail', 'Ace Fireplace', '18:50'),
                $this->rc('own-line', 'Missed', 'Tiara Maranon', '18:45'),
                $this->rc('spam', 'Accepted', 'A1 - New Client', '18:55'),
                $this->rc('blocked', 'Blocked', 'A1 - New Client', '18:55'),
                $this->rc('old', 'Missed', 'A1 - New Client', '17:00'),
                $this->rc('answered', 'Accepted', 'A1 - New Client', '18:55', answered: true),
            ], 'paging' => ['page' => 1]]),
        ]);

        $this->artisan('ezbizi:watch-missed-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();
        $this->artisan('ezbizi:watch-missed-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();

        $events = AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.call.missed')->get()
            ->mapWithKeys(fn ($e) => [$e->payload['call_id'] => [$e->payload['headline'], $e->payload['details'], $e->payload['to_number']]])
            ->sortKeys()->all();

        $this->assertSame([
            'missed' => ['Missed call - A-1 Chimney', '(212) 555-1212 - A1 - New Client', '+15550009999'],
            'own-line' => ['Missed call', '(212) 555-1212 - Tiara Maranon', '+15550009999'],
            'vm' => ['Missed call - Ace Fireplace', '(212) 555-1212 - Ace Fireplace - left a voicemail', '+15550009999'],
        ], $events);
    }

    public function test_a_call_just_before_midnight_is_read_after_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 00:01', 'America/New_York'));
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => fn ($request) => Http::response(['records' => str_starts_with((string) $request['dateFrom'], '2026-10-07')
                ? [$this->rc('late', 'Missed', 'A1 - New Client', '03:59', day: '2026-10-08')]
                : [], 'paging' => ['page' => 1]]),
        ]);

        $this->artisan('ezbizi:watch-missed-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();

        $this->assertSame(['late'], AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.call.missed')
            ->get()->map(fn ($e) => $e->payload['call_id'])->all());
    }

    private function rc(string $id, string $result, string $queue, string $utc, bool $answered = false, string $day = '2026-10-07'): array
    {
        return [
            'id' => $id,
            'direction' => 'Inbound',
            'result' => $result,
            'startTime' => "{$day}T{$utc}:00Z",
            'from' => ['phoneNumber' => '+12125551212', 'name' => 'CALLER'],
            'to' => ['phoneNumber' => '+15550009999'],
            'legs' => array_values(array_filter([
                ['legType' => 'Accept', 'result' => $result, 'to' => ['name' => 'Main - IVR']],
                ['legType' => 'Accept', 'result' => $result, 'to' => ['name' => $queue]],
                $answered ? ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748'], 'duration' => 60] : null,
            ])),
        ];
    }
}
