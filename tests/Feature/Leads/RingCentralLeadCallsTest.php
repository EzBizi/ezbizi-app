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
            $settings->set('leads.line_markets', $t['org'], ['NJ' => (string) $t['franchise']->id, 'CLE' => 'Ohio']);   // a picked branch, and a migrated name
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
                self::rc('5', '+15550000005', '2026-10-14T15:20:00Z', queue: 'Ana Diaz'),               // a person's direct line: shown too (2026-10-16)
                self::rc('6', '+15550000006', '2026-10-14T15:30:00Z', '+18000000002'),
            ], self::LINES, $t['company']->id);

            $this->assertSame(5, $filed, 'calls 1, 2 (same caller), 4, 5 and 6');
            $rc = Lead::query()->where('source', Lead::SOURCE_RINGCENTRAL)->orderBy('received_at')->get();
            $this->assertCount(4, $rc, 'one lead per caller');
            $this->assertSame(RingCentralLeadCalls::EXISTING_CLIENT, $rc[1]->source_status, 'existing client, tagged');

            $first = $rc[0];
            $this->assertSame('NJ - GMB', $first->category);
            $this->assertSame($t['franchise']->id, $first->franchise_id, 'NJ lines are New Jersey');
            $calls = $first->conversations()->orderBy('occurred_at')->get();
            $this->assertSame(['rc_1', 'rc_2'], $calls->pluck('remote_id')->all());
            $this->assertSame('ringcentral:R1', $calls[0]->recording_url);
            $this->assertSame(['+17320000001', 'NJ - GMB'], [$calls[0]->line_number, $calls[0]->line_name], 'the number they dialled (Jose, 2026-10-20)');
            $this->assertSame(95000, $calls[0]->duration_ms);
            $this->assertNull($calls[1]->duration_ms, 'missed: rang out');
            // Who picked up, or whose phones rang (Jose, 2026-10-20).
            $this->assertSame(['Ana Diaz', null], [$calls[0]->answered_by, $calls[0]->rang]);
            $this->assertSame([null, 'Ana Diaz'], [$calls[1]->answered_by, $calls[1]->rang]);
            // A row filed before this was kept is filled by the next read.
            $calls[0]->forceFill(['answered_by' => null])->save();
            app(RingCentralLeadCalls::class)->file([self::rc('1', '+15550000001', '2026-10-14T14:00:00Z')], self::LINES, $t['company']->id);
            $this->assertSame('Ana Diaz', $calls[0]->refresh()->answered_by);

            $this->assertSame(Company::query()->where('name', 'AngiSweep')->value('id'), $rc[3]->company_id, 'a brand line is its company');
            $this->assertNull($rc[3]->franchise_id);

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

    /** Jose, 2026-10-16 (James Blanke, C-06974): a known customer's hang-up is Existing client, never spam. */
    public function test_a_customers_hang_up_is_existing_client_not_spam(): void
    {
        $t = $this->setUpTenant();

        $this->inTenant($t['org'], function () use ($t) {
            \App\Models\Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'James Blanke', 'phone' => '(314) 409-1888']);
            app(RingCentralLeadCalls::class)->file([
                self::rc('50', '+13144091888', now()->subMinutes(30)->toIso8601String(), queue: null,
                    extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => [], 'result' => 'Accepted']),
                self::rc('51', '+13144091999', now()->subMinutes(30)->toIso8601String()),   // pressed New, a stranger
            ], self::LINES, $t['company']->id);

            $tags = Lead::query()->orderBy('phone')->pluck('source_status', 'phone')->all();
            $this->assertSame(['+13144091888' => RingCentralLeadCalls::EXISTING_CLIENT, '+13144091999' => null], $tags);
        });
    }

    /** Jose, 2026-10-16: "all calls should show up, inbound - outbound - direct, etc." */
    public function test_our_calls_out_show_on_the_callers_lead_or_their_own_and_never_alert(): void
    {
        $t = $this->setUpTenant();
        $out = fn (string $id, string $to, string $result, array $extra = []) => $extra + [
            'id' => $id, 'direction' => 'Outbound', 'started_at' => now()->subMinutes(20)->toIso8601String(),
            'from' => '+17320000001', 'to_number' => $to, 'caller_label' => 'Christian Francia', 'queue' => null,
            'result' => $result, 'rang' => [], 'answered_by' => null, 'talk_seconds' => null, 'duration' => 44, 'recording_id' => null,
        ];

        $this->inTenant($t['org'], function () use ($t, $out) {
            \App\Models\Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Old Client', 'phone' => '+15550000072']);
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([self::rc('70', '+15550000070', now()->subHour()->toIso8601String())], self::LINES, $t['company']->id);
            $calls->file([
                $out('71', '+15550000070', 'Call connected'),                 // calling back a lead: on its thread
                $out('72', '+15550000072', 'Call connected'),                 // a customer with no lead: not a lead
                $out('73', '+15550000073', 'No Answer'),                      // a stranger: a lead of its own, Outbound
                $out('74', '+18000000002', 'Call connected'),                 // one of our own lines: internal
                self::rc('75', '+17320000001', now()->subMinutes(10)->toIso8601String()),  // our line calling in: internal
            ], self::LINES, $t['company']->id);

            $leads = Lead::query()->orderBy('phone')->pluck('source_status', 'phone')->all();
            $this->assertSame(['+15550000070' => null, '+15550000073' => RingCentralLeadCalls::OUTBOUND], $leads);
            $back = LeadConversation::query()->where('remote_id', 'rc_71')->firstOrFail();
            $this->assertSame([LeadConversation::FROM_ADVERTISER, 44000, 'Christian Francia'], [$back->participant, $back->duration_ms, $back->text]);
            $this->assertNull(LeadConversation::query()->where('remote_id', 'rc_73')->value('duration_ms'), 'nobody picked up');
        });

        $events = \App\Models\AutomationEvent::withoutGlobalScopes()->pluck('event_key')->countBy();
        $this->assertSame(0, $events['lead.missed_call'] ?? 0, 'our calls never alert');
        $this->assertSame(0, $events['lead.contact_received'] ?? 0);

        $thread = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/'.$this->inTenant($t['org'], fn () => Lead::query()->where('phone', '+15550000070')->value('id')))->json('data.conversations');
        $ours = collect($thread)->firstWhere('from', 'ADVERTISER');
        $this->assertSame(['Christian Francia', null], [$ours['sent_by'], $ours['text']], 'the card says who called');
    }

    /**
     * Jose, 2026-10-19: answered calls showed "Missed call", and live ones too.
     * RingCentral lists a call while it is live; the next sync must correct it.
     */
    public function test_a_live_call_is_in_progress_and_corrected_when_it_ends(): void
    {
        $t = $this->setUpTenant();
        $live = ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => [], 'result' => 'In Progress'];
        $at = now()->subMinutes(5)->toIso8601String();
        $this->inTenant($t['org'], function () use ($t, $live, $at) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([self::rc('95', '+15550000095', $at, extra: $live), self::rc('96', '+15550000096', $at, extra: $live)], self::LINES, $t['company']->id);
            $this->assertSame(RingCentralLeadCalls::IN_PROGRESS, Lead::query()->where('phone', '+15550000095')->value('source_status'));
            $this->assertSame('In progress', LeadConversation::query()->where('remote_id', 'rc_95')->value('text'));

            // It ends: 95 was answered and recorded, 96 rang out.
            $calls->file([
                self::rc('95', '+15550000095', $at, extra: ['result' => 'Accepted']),
                self::rc('96', '+15550000096', $at, extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'result' => 'Missed']),
            ], self::LINES, $t['company']->id);
            $row = LeadConversation::query()->where('remote_id', 'rc_95')->first();
            $this->assertNull(Lead::query()->where('phone', '+15550000095')->value('source_status'));
            $this->assertNull($row->text);
            $this->assertSame('ringcentral:R95', $row->recording_url);
            $this->assertSame(95000, $row->duration_ms);
            $this->assertSame(RingCentralLeadCalls::MISSED, Lead::query()->where('phone', '+15550000096')->value('source_status'));
        });

        // Not while live; once, when it ended unanswered.
        $this->assertSame(1, \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.missed_call')->count());
    }

    /**
     * Jose, 2026-10-19 ("the in progress does not seem to be working still"):
     * RingCentral's call log lists a live call as "Accepted", queue reached,
     * nobody answered yet - never "In Progress". Only active-calls says it is
     * live, and the command marks it so.
     */
    public function test_a_call_on_the_active_list_is_in_progress_whatever_the_log_says(): void
    {
        $t = $this->setUpTenant();
        $asLogged = ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => [], 'result' => 'Accepted'];

        $this->inTenant($t['org'], function () use ($t, $asLogged) {
            app(RingCentralLeadCalls::class)->file([
                self::rc('97', '+17322757027', now()->subMinutes(3)->toIso8601String(), extra: $asLogged + ['live' => true]),
            ], self::LINES, $t['company']->id);

            $this->assertSame(RingCentralLeadCalls::IN_PROGRESS, Lead::query()->value('source_status'));
            $this->assertSame('In progress', LeadConversation::query()->where('remote_id', 'rc_97')->value('text'));
        });
        $this->assertSame(0, \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.missed_call')->count(), 'no alert on a live call');

        // The active list, read by the same ids the call log uses.
        $this->inTenant($t['org'], function () use ($t) {
            $rc = new \App\Models\SocialConnection;
            $rc->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'ringcentral', 'remote_id' => '123', 'display_name' => 'RingCentral']);
            $rc->connect(['refresh_token' => '-', 'server' => 'https://rc.test', 'client_id' => 'id', 'client_secret' => 's', 'jwt' => 'j']);
            $rc->save();
            \Illuminate\Support\Facades\Http::fake([
                'rc.test/restapi/oauth/token' => \Illuminate\Support\Facades\Http::response(['access_token' => 't', 'expires_in' => 3600]),
                'rc.test/restapi/v1.0/account/~/active-calls*' => \Illuminate\Support\Facades\Http::response(['records' => [['id' => 'ALz3', 'result' => 'In Progress']]]),
            ]);
            $this->assertSame(['ALz3' => true], app(RingCentralCallLog::class)->activeCallIds());
        });
    }

    /**
     * Desk, 2026-10-20: a 30-day catch-up stopped on "did not list the calls in
     * progress (429)". Without the list, only a recent call might be live.
     */
    public function test_with_no_active_list_older_calls_are_filed_and_recent_ones_wait(): void
    {
        $t = $this->setUpTenant();
        $this->inTenant($t['org'], function () use ($t) {
            $rc = new \App\Models\SocialConnection;
            $rc->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'ringcentral', 'remote_id' => '123', 'display_name' => 'RingCentral']);
            $rc->connect(['refresh_token' => '-', 'server' => 'https://rc.test', 'client_id' => 'id', 'client_secret' => 's', 'jwt' => 'j']);
            $rc->save();
        });
        $record = fn (string $id, string $from, $at) => ['id' => $id, 'direction' => 'Inbound', 'startTime' => $at->toIso8601String(),
            'from' => ['phoneNumber' => $from], 'to' => ['phoneNumber' => '+17320000001'], 'result' => 'Accepted',
            'legs' => [['legType' => 'PstnToSip', 'from' => ['name' => 'Sasha'], 'result' => 'Call connected', 'duration' => 60]]];
        $records = [$record('old', '+15550000001', now()->subDays(5)), $record('new', '+15550000002', now()->subHour())];
        \Illuminate\Support\Facades\Http::fake([
            'rc.test/restapi/oauth/token' => \Illuminate\Support\Facades\Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'rc.test/restapi/v1.0/account/~/active-calls*' => \Illuminate\Support\Facades\Http::response([], 429),
            'rc.test/restapi/v1.0/account/~/phone-number*' => \Illuminate\Support\Facades\Http::response(['records' => []]),
            // Each day asks for its own calls.
            'rc.test/restapi/v1.0/account/~/call-log*' => function ($request) use ($records) {
                $from = \Carbon\CarbonImmutable::parse($request['dateFrom']);
                $to = \Carbon\CarbonImmutable::parse($request['dateTo']);

                return \Illuminate\Support\Facades\Http::response(['records' => array_values(array_filter($records,
                    fn ($r) => \Carbon\CarbonImmutable::parse($r['startTime'])->between($from, $to)))]);
            },
        ]);

        $this->artisan('ezbizi:leads-ringcentral', ['--days' => 30])->assertSuccessful();

        $this->inTenant($t['org'], fn () => $this->assertSame(['rc_old'],
            LeadConversation::query()->pluck('remote_id')->all(), 'the hour-old call waits for a run that can tell'));
        $this->inTenant($t['org'], fn () => $this->assertSame('Sasha', LeadConversation::query()->value('answered_by')));
    }

    /** Jose, 2026-10-19: "think we can also get the lenght of the call" (B Imperato, 0:00:01). */
    public function test_an_unanswered_call_says_how_long_it_lasted(): void
    {
        $t = $this->setUpTenant();
        $this->inTenant($t['org'], function () use ($t) {
            app(RingCentralLeadCalls::class)->file([
                self::rc('98', '+16313106166', now()->subMinutes(30)->toIso8601String(), queue: null,
                    extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => [], 'result' => 'Accepted', 'duration' => 1]),
                self::rc('99', '+16313106199', now()->subMinutes(30)->toIso8601String(),
                    extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'result' => 'Missed', 'duration' => 75]),
            ], self::LINES, $t['company']->id);

            $this->assertSame('Possible spam · 0:01', LeadConversation::query()->where('remote_id', 'rc_98')->value('text'));
            $this->assertSame('Missed call · 1:15', LeadConversation::query()->where('remote_id', 'rc_99')->value('text'));
        });
    }

    /** Jose, 2026-10-19: "Fetching" - answered, RingCentral has not sent the recording yet. */
    public function test_an_answered_call_is_fetching_until_its_recording_comes(): void
    {
        $t = $this->setUpTenant();
        $at = now()->subMinutes(10)->toIso8601String();
        $this->inTenant($t['org'], function () use ($t, $at) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([
                self::rc('60', '+15550000060', $at, extra: ['recording_id' => null]),
                self::rc('61', '+15550000061', now()->subHours(3)->toIso8601String(), extra: ['recording_id' => null]),   // long past: just no recording
            ], self::LINES, $t['company']->id);
            $lead = Lead::query()->where('phone', '+15550000060')->sole();
            $this->assertSame(RingCentralLeadCalls::FETCHING, $lead->source_status);
            $this->assertSame('Fetching recording', LeadConversation::query()->where('remote_id', 'rc_60')->value('text'));
            $this->assertNull(Lead::query()->where('phone', '+15550000061')->value('source_status'));

            // The recording arrives: the tag goes and the player can load it.
            $calls->file([self::rc('60', '+15550000060', $at)], self::LINES, $t['company']->id);
            $row = LeadConversation::query()->where('remote_id', 'rc_60')->sole();
            $this->assertSame(['ringcentral:R60', null], [$row->recording_url, $row->text]);
            $this->assertNull($lead->refresh()->source_status);
        });
    }

    /** Desk, 2026-10-19: 23 leads stuck In progress once our call landed after theirs. */
    public function test_our_later_call_does_not_freeze_their_in_progress_tag(): void
    {
        $t = $this->setUpTenant();
        $at = now()->subMinutes(20)->toIso8601String();
        $unanswered = ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null];
        $this->inTenant($t['org'], function () use ($t, $at, $unanswered) {
            $calls = app(RingCentralLeadCalls::class);
            $calls->file([self::rc('70', '+15550000070', $at, extra: $unanswered + ['result' => 'Accepted', 'live' => true])], self::LINES, $t['company']->id);
            // We call them back while theirs still reads live.
            $calls->file([[
                'id' => '71', 'direction' => 'Outbound', 'started_at' => now()->subMinutes(5)->toIso8601String(), 'from' => '+17320000001',
                'to_number' => '+15550000070', 'caller_label' => 'Sheryl Del Rosario', 'queue' => null, 'result' => 'Call connected',
                'rang' => [], 'answered_by' => null, 'talk_seconds' => null, 'duration' => 30, 'recording_id' => null,
            ]], self::LINES, $t['company']->id);
            // Theirs ends unanswered.
            $calls->file([self::rc('70', '+15550000070', $at, extra: $unanswered + ['result' => 'Missed'])], self::LINES, $t['company']->id);

            $this->assertSame(RingCentralLeadCalls::MISSED, Lead::query()->where('phone', '+15550000070')->value('source_status'));
        });
    }

    /** A missed call on a person's direct line alerts like any missed call. */
    public function test_a_missed_call_on_a_direct_line_alerts(): void
    {
        $t = $this->setUpTenant();
        $this->inTenant($t['org'], fn () => app(RingCentralLeadCalls::class)->file([
            self::rc('80', '+15550000080', now()->subMinutes(20)->toIso8601String(), queue: 'Jason Roman',
                extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => ['Jason Roman'], 'result' => 'Missed']),
        ], self::LINES, $t['company']->id));

        $this->assertSame(1, \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.missed_call')->count());
        $this->assertSame(RingCentralLeadCalls::MISSED, $this->inTenant($t['org'], fn () => Lead::query()->value('source_status')));
        // The alert names the line they dialled (Automations, 2026-10-20).
        $after = \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.missed_call')->value('payload')['after'];
        $this->assertSame(['NJ - GMB', null], [$after['line'], $after['contact_name']]);
    }

    /** A known customer's missed call says who; a line with no name says its number. */
    public function test_a_missed_call_alert_names_a_known_caller_and_an_unnamed_line_by_number(): void
    {
        $t = $this->setUpTenant();
        $this->inTenant($t['org'], function () use ($t) {
            \App\Models\Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-0081', 'name' => 'Jane Doe', 'phone' => '+15550000081']);
            app(RingCentralLeadCalls::class)->file([
                self::rc('81', '+15550000081', now()->subMinutes(20)->toIso8601String(), '+19990000000',
                    extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => ['Ana Diaz'], 'result' => 'Missed']),
            ], self::LINES, $t['company']->id);
        });

        $after = \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.missed_call')->value('payload')['after'];
        $this->assertSame(['+19990000000', 'Jane Doe'], [$after['line'], $after['contact_name']]);
    }

    /** Jose, 2026-10-19: a call from someone on an archived lead reopens it - "yes of course". */
    public function test_a_call_reopens_the_callers_archived_lead_at_any_age_but_a_hang_up_or_our_call_does_not(): void
    {
        $t = $this->setUpTenant();
        $old = fn (string $phone) => Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => uniqid(), 'lead_type' => 'MESSAGE', 'phone' => $phone,
            'phone_digits' => ltrim($phone, '+'), 'status' => Lead::STATUS_ARCHIVED, 'received_at' => now()->subMonths(5)]);

        $this->inTenant($t['org'], function () use ($t, $old) {
            $called = $old('+15550000090');
            $hungUp = $old('+15550000091');
            $weCalled = $old('+15550000092');
            app(RingCentralLeadCalls::class)->file([
                self::rc('90', '+15550000090', now()->subMinutes(30)->toIso8601String()),
                self::rc('91', '+15550000091', now()->subMinutes(30)->toIso8601String(), queue: null,
                    extra: ['answered_by' => null, 'talk_seconds' => null, 'recording_id' => null, 'rang' => [], 'result' => 'Accepted']),
                ['id' => '92', 'direction' => 'Outbound', 'started_at' => now()->subMinutes(30)->toIso8601String(), 'from' => '+17320000001',
                    'to_number' => '+15550000092', 'caller_label' => 'Ana Diaz', 'queue' => null, 'result' => 'Call connected',
                    'rang' => [], 'answered_by' => null, 'talk_seconds' => null, 'duration' => 30, 'recording_id' => null],
            ], self::LINES, $t['company']->id);

            $this->assertSame(Lead::STATUS_NEW, $called->refresh()->status, 'their own five-month-old lead, reopened');
            $this->assertSame(1, $called->conversations()->count());
            $this->assertSame(Lead::STATUS_ARCHIVED, $hungUp->refresh()->status, 'a menu hang-up does not reopen');
            $this->assertSame(Lead::STATUS_ARCHIVED, $weCalled->refresh()->status, 'our call does not reopen');
            $this->assertSame(3, Lead::query()->count(), 'no new leads: every call found its person');
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
