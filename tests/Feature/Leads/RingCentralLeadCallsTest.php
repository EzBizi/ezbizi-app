<?php

namespace Tests\Feature\Leads;

use App\Models\CallReportTranscript;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\RingCentralLeadCalls;
use App\Support\Reports\Experimental\RingCentralCallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-15: "show the Ring Central Calls in Leads as well just like the LS Calls". */
class RingCentralLeadCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LINES = ['+17320000001' => 'NJ - GMB', '+18000000002' => 'AngiSweep - Main', '+12160000003' => 'CLE - LS Ads'];

    private static function rc(string $id, string $from, string $at, string $to = '+17320000001', ?string $queue = 'A1 - New Client', array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'direction' => 'Inbound', 'started_at' => $at, 'from' => $from, 'to_number' => $to,
            'queue' => $queue, 'rang' => ['Ana Diaz'], 'answered_by' => 'Ana Diaz', 'talk_seconds' => 95, 'recording_id' => 'R'.$id,
        ];
    }

    private function setUpTenant(): array
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            Franchise::query()->whereKey($t['franchise']->id)->update(['name' => 'New Jersey', 'company_id' => $t['company']->id]);
            Company::create(['organization_id' => $t['org']->id, 'name' => 'AngiSweep']);
            // The business's own map and queue, from Settings (no A-1 in the code).
            $settings = app(\App\Support\Settings\SettingsResolver::class);
            $settings->set('leads.line_markets', $t['org'], ['NJ' => 'New Jersey', 'CLE' => 'Ohio']);
            $settings->set('leads.existing_client_queue', $t['org'], 'A1 - Existing Client');
        });

        return $t;
    }

    public function test_a_direct_call_is_a_lead_once_and_an_ls_call_or_a_customer_is_not(): void
    {
        $t = $this->setUpTenant();

        $this->inTenant($t['org'], function () use ($t) {
            // Google already has this one as an LS call, 40 seconds apart.
            $ls = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'g1',
                'lead_type' => 'PHONE_CALL', 'phone' => '+15550000009', 'phone_digits' => '15550000009', 'status' => 'new', 'received_at' => '2026-10-14 15:00:00']);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $ls->id, 'remote_id' => 'gc1',
                'channel' => 'PHONE_CALL', 'participant' => 'CONSUMER', 'occurred_at' => '2026-10-14 15:00:40']);

            $filed = app(RingCentralLeadCalls::class)->file([
                self::rc('1', '+15550000001', '2026-10-14T14:00:00Z'),
                self::rc('2', '+15550000001', '2026-10-14T16:00:00Z', '+12160000003', null, ['talk_seconds' => null, 'answered_by' => null, 'recording_id' => null]),
                self::rc('3', '+15550000009', '2026-10-14T15:00:00Z'),                                   // the LS call
                self::rc('4', '+15550000004', '2026-10-14T15:10:00Z', queue: 'A1 - Existing Client'), // shown too, tagged
                self::rc('5', '+15550000005', '2026-10-14T15:20:00Z', queue: 'Ana Diaz'),               // a person's direct line
                self::rc('6', '+15550000006', '2026-10-14T15:30:00Z', '+18000000002'),
            ], self::LINES, $t['company']->id);

            $this->assertSame(4, $filed, 'calls 1, 2 (same caller), 4 and 6');
            $rc = Lead::query()->where('source', Lead::SOURCE_RINGCENTRAL)->orderBy('received_at')->get();
            $this->assertCount(3, $rc, 'one lead per caller');
            $this->assertSame(RingCentralLeadCalls::EXISTING_CLIENT, $rc[1]->source_status, 'existing client, tagged');

            $first = $rc[0];
            $this->assertSame('NJ - GMB', $first->category);
            $this->assertSame($t['franchise']->id, $first->franchise_id, 'NJ lines are New Jersey');
            $calls = $first->conversations()->orderBy('occurred_at')->get();
            $this->assertSame(['rc_1', 'rc_2'], $calls->pluck('remote_id')->all());
            $this->assertSame('ringcentral:R1', $calls[0]->recording_url);
            $this->assertSame(95000, $calls[0]->duration_ms);
            $this->assertNull($calls[1]->duration_ms, 'missed: rang out');

            $this->assertSame(Company::query()->where('name', 'AngiSweep')->value('id'), $rc[2]->company_id, 'a brand line is its company');
            $this->assertNull($rc[2]->franchise_id);

            // Run again: nothing twice.
            $this->assertSame(0, app(RingCentralLeadCalls::class)->file([self::rc('1', '+15550000001', '2026-10-14T14:00:00Z')], self::LINES, $t['company']->id));
        });
    }

    public function test_a_call_google_reports_later_is_withdrawn_and_reports_transcript_is_reused(): void
    {
        $t = $this->setUpTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([self::rc('7', '+15550000007', now()->subHour()->toIso8601String()),
                self::rc('8', '+15550000008', now()->subHour()->toIso8601String())], self::LINES, $t['company']->id);

            CallReportTranscript::create(['organization_id' => $t['org']->id, 'call_id' => '8', 'transcript' => 'I need a chimney sweep', 'source' => 'whisper']);
            $this->assertSame(1, $calls->fillTranscripts());
            $this->assertSame('I need a chimney sweep', LeadConversation::query()->where('remote_id', 'rc_8')->value('transcript'));

            // Google's copy of call 7 arrives after it was filed.
            $ls = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'g7',
                'lead_type' => 'PHONE_CALL', 'phone' => '+15550000007', 'phone_digits' => '15550000007', 'status' => 'new', 'received_at' => now()->subHour()]);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $ls->id, 'remote_id' => 'gc7',
                'channel' => 'PHONE_CALL', 'participant' => 'CONSUMER', 'occurred_at' => now()->subHour()->addSeconds(30)]);

            // A second call from that caller, an hour after Google's: it goes on
            // the LS lead and is NOT a duplicate of itself.
            $calls->file([self::rc('7b', '+15550000007', now()->toIso8601String())], self::LINES, $t['company']->id);
            $this->assertTrue($ls->conversations()->where('remote_id', 'rc_7b')->exists(), 'filed on the caller\'s LS lead');

            $this->assertSame(1, $calls->withdrawDuplicates(\Carbon\CarbonImmutable::now()->subDays(2)));
            $this->assertTrue($ls->conversations()->where('remote_id', 'rc_7b')->exists(), 'never matched to itself');
            $this->assertSame(['15550000008'], Lead::query()->where('source', Lead::SOURCE_RINGCENTRAL)->pluck('phone_digits')->all());
        });
    }

    public function test_the_recording_plays_from_ringcentral_and_the_call_is_never_unread(): void
    {
        $t = $this->setUpTenant();
        $lead = $this->inTenant($t['org'], function () use ($t) {
            app(RingCentralLeadCalls::class)->file([self::rc('9', '+15550000010', now()->subHour()->toIso8601String())], self::LINES, $t['company']->id);

            return Lead::query()->where('source', Lead::SOURCE_RINGCENTRAL)->sole();
        });
        $conversation = $this->inTenant($t['org'], fn () => $lead->conversations()->sole());

        // The organization's own RingCentral connection (8a640f19); env is A1-Group's alone.
        $this->inTenant($t['org'], function () use ($t) {
            $rc = new \App\Models\SocialConnection;
            $rc->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'ringcentral', 'remote_id' => '123', 'display_name' => 'RingCentral']);
            $rc->connect(['refresh_token' => '-', 'server' => 'https://rc.test', 'client_id' => 'id', 'client_secret' => 's', 'jwt' => 'j']);
            $rc->save();
        });
        \Illuminate\Support\Facades\Http::fake([
            'rc.test/restapi/oauth/token' => \Illuminate\Support\Facades\Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'rc.test/restapi/v1.0/account/~/recording/R9/content' => \Illuminate\Support\Facades\Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->signedInAs($t['admin'])->get("/api/v1/leads/{$lead->id}/conversations/{$conversation->id}/recording")
            ->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->json('data'))->firstWhere('id', $lead->id);
        $this->assertNotNull($row, 'an administrator sees RC Calls');
        $this->assertFalse($row['is_unread']);
    }

    /** 884 "New reply" bells in 30 minutes (2026-10-15): a call is not a reply; only a missed one is news. */
    public function test_only_a_missed_call_is_announced_and_calls_owe_no_reply(): void
    {
        $t = $this->setUpTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([
                self::rc('20', '+15550000020', now()->subHours(3)->toIso8601String()),
                self::rc('21', '+15550000020', now()->subHours(2)->toIso8601String()),   // calls again, answered
                self::rc('22', '+15550000022', now()->subMinutes(30)->toIso8601String(), extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null]),
            ], self::LINES, $t['company']->id);
            // Running again files nothing and announces nothing.
            $calls->file([self::rc('22', '+15550000022', now()->subMinutes(30)->toIso8601String(), extra: ['answered_by' => null])], self::LINES, $t['company']->id);
        });

        $events = \App\Models\AutomationEvent::withoutGlobalScopes()->pluck('event_key')->countBy();
        $this->assertSame(0, $events['lead.customer_replied'] ?? 0);
        $this->assertSame(0, $events['lead.contact_received'] ?? 0);
        $this->assertSame(1, $events['lead.missed_call'] ?? 0, 'the one missed call, once');

        $unreplied = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?unreplied=1')->json('data');
        $this->assertSame([], array_column($unreplied, 'id'), 'a call owes no reply');
    }

    /** Jose, 2026-10-16: menu hang-ups are Possible spam, still leads, but "excluded from the missed call alerts". */
    public function test_unanswered_calls_are_tagged_and_menu_hang_ups_are_not_announced(): void
    {
        $t = $this->setUpTenant();
        $unanswered = ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null];

        $this->inTenant($t['org'], function () use ($t, $unanswered) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([
                self::rc('30', '+15550000030', now()->subMinutes(30)->toIso8601String(), queue: null, extra: $unanswered + ['rang' => [], 'result' => 'Accepted']),
                self::rc('31', '+15550000031', now()->subMinutes(30)->toIso8601String(), extra: $unanswered + ['result' => 'Missed']),
                self::rc('32', '+15550000032', now()->subMinutes(30)->toIso8601String(), queue: null, extra: $unanswered + ['rang' => [], 'result' => 'Voicemail']),
                // Missed before any queue (RingCentral says Missed, not Accepted): not spam.
                self::rc('35', '+15550000035', now()->subHours(3)->toIso8601String(), queue: null, extra: $unanswered + ['rang' => [], 'result' => 'Missed']),
                // Missed hours ago (a catch-up run): filed, tagged, not announced.
                self::rc('34', '+15550000034', now()->subHours(3)->toIso8601String(), extra: $unanswered + ['result' => 'Missed']),
                // The missed caller hangs up in the menu later: still Missed call.
                self::rc('33', '+15550000031', now()->subMinutes(20)->toIso8601String(), queue: null, extra: $unanswered + ['rang' => [], 'result' => 'Accepted']),
            ], self::LINES, $t['company']->id);

            $tags = Lead::query()->orderBy('phone')->pluck('source_status', 'phone')->all();
            $this->assertSame([
                '+15550000030' => RingCentralLeadCalls::POSSIBLE_SPAM,
                '+15550000031' => RingCentralLeadCalls::MISSED,
                '+15550000032' => RingCentralLeadCalls::VOICEMAIL,
                '+15550000034' => RingCentralLeadCalls::MISSED,
                '+15550000035' => RingCentralLeadCalls::MISSED,
            ], $tags);
            $this->assertSame('Possible spam', LeadConversation::query()->where('remote_id', 'rc_30')->value('text'));
        });

        $events = \App\Models\AutomationEvent::withoutGlobalScopes()->pluck('event_key')->countBy();
        $this->assertSame(2, $events['lead.missed_call'] ?? 0, 'missed and voicemail, not the hang-ups');

        $rc = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?source=ringcentral')->json('data');
        $this->assertCount(5, $rc, 'all five under RC Calls');
    }

    /** Jose, 2026-10-16: "stablished as a public product would" - no business's names in the code. */
    public function test_a_business_with_no_settings_gets_no_one_elses_markets_or_queue(): void
    {
        $t = $this->provisionTenant('Everdoor');

        $this->inTenant($t['org'], function () use ($t) {
            app(RingCentralLeadCalls::class)->file([
                self::rc('40', '+15550000040', now()->subMinutes(30)->toIso8601String(), queue: 'A1 - Existing Client'),
            ], self::LINES, $t['company']->id);

            $lead = Lead::query()->firstOrFail();
            $this->assertNull($lead->source_status, 'no existing-client queue configured');
            $this->assertNull($lead->franchise_id, 'no market map configured');
            $this->assertSame($t['company']->id, $lead->company_id);
        });
    }

    public function test_line_names_place_the_company_and_market(): void
    {
        $companies = ['a' => 'A-1 Chimney', 'b' => 'Crestline Home', 'c' => 'Ace Fireplace Services', 'd' => 'Roofirst'];
        $this->assertNull(RingCentralLeadCalls::companyFor('NJ - GMB', $companies));
        $this->assertSame('b', RingCentralLeadCalls::companyFor('Crestline', $companies));
        $this->assertSame('c', RingCentralLeadCalls::companyFor('Ace Fireplace', $companies));
        $this->assertSame('d', RingCentralLeadCalls::companyFor('08 Roofirst', $companies));
        $this->assertSame('NJ-PHL', RingCentralLeadCalls::market('NJ-PHL - SEO'));
    }
}
