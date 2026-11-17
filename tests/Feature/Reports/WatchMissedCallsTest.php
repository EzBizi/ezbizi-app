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
        $this->inTenant($tenant['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set('phone.ringcentral_companies', $tenant['org'], ['A1' => 'A-1 Chimney', 'Ace Fireplace' => 'Ace Fireplace']));

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            // The line's name as A-1 labelled it in RingCentral; the vm call's
            // number has none, so it falls back to the queue.
            '*/phone-number*' => Http::response(['records' => [
                ['phoneNumber' => '+15550009999', 'label' => 'DFW - LS Ads'],
                ['phoneNumber' => '+15550008888'],
            ]]),
            '*/call-log*' => Http::response(['records' => [
                $this->rc('missed', 'Missed', 'A1 - New Client', '18:55'),
                $this->rc('vm', 'Voicemail', 'Ace Fireplace', '18:50', to: '+15550008888'),
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
            'missed' => ['Missed call - A-1 Chimney', '(212) 555-1212 - DFW - LS Ads', '+15550009999'],
            'own-line' => ['Missed call', '(212) 555-1212 - DFW - LS Ads', '+15550009999'],
            'vm' => ['Missed call - Ace Fireplace', '(212) 555-1212 - Ace Fireplace - left a voicemail', '+15550008888'],
        ], $events);
    }

    /** Notifications, 2026-10-23: each missed call names its company, for a per-person company filter. */
    public function test_a_missed_call_carries_the_accounts_company(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 15:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set('phone.ringcentral_companies', $tenant['org'], ['A1' => 'A-1 Chimney', 'Ace Fireplace' => 'Ace Fireplace']));
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
            'experimental_reports.ringcentral.company_id' => $tenant['company']->id,
        ]);
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/phone-number*' => Http::response(['records' => []]),
            '*/call-log*' => Http::response(['records' => [$this->rc('own-line', 'Missed', 'Tiara Maranon', '18:45')], 'paging' => ['page' => 1]]),
        ]);

        $this->artisan('ezbizi:watch-missed-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();

        $payload = AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.call.missed')->sole()->payload;
        $this->assertSame($tenant['company']->id, $payload['company_id']);
        $this->assertSame($tenant['company']->name, $payload['company']);
    }

    /** 2026-11-17: Area on the missed-call task comes from the line's branch. */
    public function test_a_line_names_its_branch_by_number_then_by_market(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $s = app(\App\Support\Settings\SettingsResolver::class);
            $s->set('leads.line_markets', $tenant['org'], ['CLE' => 'Ohio']);
            $s->set('leads.line_numbers', $tenant['org'], ['+12165550000' => 'franchise:'.$tenant['franchise']->id]);
            $w = \App\Console\Commands\WatchMissedCalls::class;
            $this->assertSame($tenant['franchise']->name, $w::branchOf('+12165550000', 'CLE - PPC'), 'the number\'s place wins');
            $this->assertSame('Ohio', $w::branchOf('+12165559999', 'CLE - PPC'));
            $this->assertNull($w::branchOf('+12165559999', 'Speedy'));
        });
    }

    public function test_a_call_just_before_midnight_is_read_after_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 00:01', 'America/New_York'));
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set('phone.ringcentral_companies', $tenant['org'], ['A1' => 'A-1 Chimney', 'Ace Fireplace' => 'Ace Fireplace']));

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/phone-number*' => Http::response(['records' => []]),
            '*/call-log*' => fn ($request) => Http::response(['records' => str_starts_with((string) $request['dateFrom'], '2026-10-07')
                ? [$this->rc('late', 'Missed', 'A1 - New Client', '03:59', day: '2026-10-08')]
                : [], 'paging' => ['page' => 1]]),
        ]);

        $this->artisan('ezbizi:watch-missed-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();

        $this->assertSame(['late'], AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.call.missed')
            ->get()->map(fn ($e) => $e->payload['call_id'])->all());
    }

    private function rc(string $id, string $result, string $queue, string $utc, bool $answered = false, string $day = '2026-10-07', string $to = '+15550009999'): array
    {
        return [
            'id' => $id,
            'direction' => 'Inbound',
            'result' => $result,
            'startTime' => "{$day}T{$utc}:00Z",
            'from' => ['phoneNumber' => '+12125551212', 'name' => 'CALLER'],
            'to' => ['phoneNumber' => $to],
            'legs' => array_values(array_filter([
                ['legType' => 'Accept', 'result' => $result, 'to' => ['name' => 'Main - IVR']],
                ['legType' => 'Accept', 'result' => $result, 'to' => ['name' => $queue]],
                $answered ? ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748'], 'duration' => 60] : null,
            ])),
        ];
    }
}
