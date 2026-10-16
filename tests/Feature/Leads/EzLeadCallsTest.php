<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Leads\EzLeadCalls;
use App\Support\Leads\RingCentralLeadCalls as RC;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-16: "LS Calls RC Calls EZ Calls ... as well as EZ Texts". */
class EzLeadCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private PhoneConnection $connection;

    private PhoneNumber $line;

    private PhoneNumber $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Everdoor');
        $this->inTenant($this->t['org'], function () {
            $this->connection = new PhoneConnection([
                'organization_id' => $this->t['org']->id, 'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $this->t['company']->id, 'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'AC'.str_repeat('e', 30), 'api_key_sid' => 'SK'.str_repeat('e', 30),
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $this->connection->writeSecrets('s', 't');
            $this->connection->save();
            foreach (['line' => ['+12035550222', true], 'other' => ['+12035550333', false]] as $name => [$e164, $lead]) {
                $this->{$name} = PhoneNumber::query()->create([
                    'organization_id' => $this->t['org']->id, 'connection_id' => $this->connection->id,
                    'scope_type' => PhoneNumber::SCOPE_COMPANY, 'scope_id' => $this->t['company']->id,
                    'twilio_sid' => 'PN'.str_repeat($lead ? 'a' : 'b', 32), 'e164' => $e164, 'label' => 'Main '.$name,
                    'after_hours' => 'voicemail', 'lead_line' => $lead, 'sms_outbound_allowed' => true,
                ]);
            }
        });
    }

    private function ring(string $from, array $fields, ?PhoneNumber $on = null, string $ago = '-5 minutes'): PhoneCall
    {
        $c = new PhoneCall;
        $c->forceFill($fields + [
            'organization_id' => $this->t['org']->id, 'connection_id' => $this->connection->id,
            'number_id' => ($on ?? $this->line)->id, 'twilio_sid' => 'CA'.uniqid(), 'direction' => 'inbound',
            'from_e164' => $from, 'to_e164' => ($on ?? $this->line)->e164, 'status' => 'completed',
            'created_at' => CarbonImmutable::now()->modify($ago),
        ])->save();

        return $c;
    }

    public function test_calls_on_lead_lines_are_tagged_and_only_missed_and_voicemail_announced(): void
    {
        $this->inTenant($this->t['org'], function () {
            Customer::factory()->create(['organization_id' => $this->t['org']->id, 'owning_franchise_id' => $this->t['franchise']->id, 'name' => 'Old Client', 'phone' => '+15550000004']);
            $this->ring('+15550000001', ['status' => 'no-answer']);                                       // hung up in the menu
            $this->ring('+15550000002', ['status' => 'no-answer', 'menu_key' => '1']);                    // reached the queue
            $this->ring('+15550000003', ['status' => 'completed', 'menu_key' => '1', 'voicemail' => true, 'voicemail_transcript' => 'Call me back']);
            $this->ring('+15550000004', ['answered_by' => $this->t['admin']->id, 'duration_seconds' => 90, 'recording_sid' => 'RE1']);
            $this->ring('+15550000005', ['status' => 'in-progress']);                                     // not over yet
            $this->ring('+15550000006', ['status' => 'no-answer', 'menu_key' => '1'], $this->other);      // not a lead line
            $this->ring('+15550000007', ['status' => 'no-answer', 'menu_key' => '1'], null, '-3 hours');  // a catch-up: quiet

            $calls = app(EzLeadCalls::class);
            $this->assertSame(5, $calls->file(CarbonImmutable::now()->subDay()));
            $this->assertSame(0, $calls->file(CarbonImmutable::now()->subDay()), 'each call once');

            $tags = Lead::query()->orderBy('phone')->pluck('source_status', 'phone')->all();
            $this->assertSame([
                '+15550000001' => RC::POSSIBLE_SPAM,
                '+15550000002' => RC::MISSED,
                '+15550000003' => RC::VOICEMAIL,
                '+15550000004' => RC::EXISTING_CLIENT,
                '+15550000007' => RC::MISSED,
            ], $tags);
            $this->assertSame([Lead::SOURCE_EZ_CALLS], Lead::query()->distinct()->pluck('source')->all());
            $answered = LeadConversation::query()->where('lead_id', Lead::query()->where('phone', '+15550000004')->value('id'))->first();
            $this->assertSame([90000, null], [$answered->duration_ms, $answered->text]);
            $this->assertStringStartsWith('twilio:', $answered->recording_url);
            $this->assertSame($this->t['admin']->name, Lead::query()->where('phone', '+15550000004')->value('dispatcher'));
            $this->assertSame('Call me back', LeadConversation::query()->where('text', 'Voicemail')->value('transcript'));
        });

        $events = AutomationEvent::withoutGlobalScopes()->pluck('event_key')->countBy();
        $this->assertSame(2, $events['lead.missed_call'] ?? 0, 'missed and voicemail; not the hang-up, the answered one or the old one');
        $this->assertSame(0, $events['lead.contact_received'] ?? 0, 'a call is not somebody writing in');
    }

    /** Jose, 2026-10-16: our calls out show too; never an alert; never our own lines. */
    public function test_our_calls_out_show_and_never_alert(): void
    {
        $this->inTenant($this->t['org'], function () {
            $this->ring('+12035550222', ['direction' => 'outbound', 'to_e164' => '+15550000020', 'user_id' => $this->t['admin']->id, 'duration_seconds' => 30]);
            $this->ring('+12035550222', ['direction' => 'outbound', 'to_e164' => '+12035550333', 'user_id' => $this->t['admin']->id]);   // our other line
            app(EzLeadCalls::class)->file(CarbonImmutable::now()->subDay());

            $lead = Lead::query()->sole();
            $this->assertSame(['+15550000020', RC::OUTBOUND], [$lead->phone, $lead->source_status]);
            $row = $lead->conversations()->sole();
            $this->assertSame([LeadConversation::FROM_ADVERTISER, 30000, $this->t['admin']->name], [$row->participant, $row->duration_ms, $row->text]);
        });
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->whereIn('event_key', ['lead.missed_call', 'lead.contact_received'])->count());
    }

    public function test_a_text_is_actionable_and_a_caller_keeps_one_lead_across_lines(): void
    {
        $lead = $this->inTenant($this->t['org'], function () {
            // Their RingCentral call yesterday: the same person.
            $rc = Lead::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->t['company']->id,
                'source' => Lead::SOURCE_RINGCENTRAL, 'remote_id' => 'rc_1', 'phone' => '+15550000009', 'phone_digits' => '15550000009',
                'status' => Lead::STATUS_NEW, 'received_at' => now()->subDay()]);
            $m = new PhoneMessage;
            $m->forceFill(['organization_id' => $this->t['org']->id, 'connection_id' => $this->connection->id, 'number_id' => $this->line->id,
                'twilio_sid' => 'SM1', 'direction' => 'inbound', 'from_e164' => '+15550000009', 'to_e164' => $this->line->e164,
                'body' => 'Can you sweep Friday?', 'created_at' => now()->subMinutes(2)])->save();

            app(EzLeadCalls::class)->file(CarbonImmutable::now()->subHour());

            $this->assertSame(1, Lead::query()->count(), 'one lead per person, whichever line');
            $this->assertSame('Can you sweep Friday?', $rc->conversations()->value('text'));

            return $rc;
        });

        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.contact_received')->count(), 'a text is somebody writing in');

        // A text of its own, unanswered: on the Unreplied list.
        $this->inTenant($this->t['org'], function () {
            $m = new PhoneMessage;
            $m->forceFill(['organization_id' => $this->t['org']->id, 'connection_id' => $this->connection->id, 'number_id' => $this->line->id,
                'twilio_sid' => 'SM2', 'direction' => 'inbound', 'from_e164' => '+15550000010', 'to_e164' => $this->line->e164,
                'body' => 'Quote please', 'created_at' => now()->subMinutes(1)])->save();
            app(EzLeadCalls::class)->file(CarbonImmutable::now()->subHour());
        });
        $unreplied = array_column($this->signedInAs($this->t['admin'])->getJson('/api/v1/leads?unreplied=1')->json('data'), 'source');
        $this->assertContains(Lead::SOURCE_EZ_TEXTS, $unreplied);
    }
}
