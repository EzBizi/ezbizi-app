<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The one follow-up Rodrigo's bot sends (Jose, 2026-09-30): three hours after
 * the automatic first reply, the same day, to a customer who went quiet - once.
 */
class FollowUpDueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30T17:05:00Z'); // 13:05 in New York
        $this->t = $this->provisionTenant('A1-Group');
    }

    /** @param  array<int, array{0: string, 1: string, 2?: bool, 3?: string}>  $said  [participant, at, automatic, text] */
    private function lead(string $name, array $said): Lead
    {
        return $this->inTenant($this->t['org'], function () use ($name, $said) {
            $last = end($said);
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => $name,
                'contact_name' => $name,
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => Carbon::parse($said[0][1]),
                'last_contact_at' => Carbon::parse($last[1]),
                'last_contact_from' => $last[0],
            ]);
            foreach ($said as $i => $s) {
                LeadConversation::create([
                    'organization_id' => $this->t['org']->id,
                    'lead_id' => $lead->id,
                    'remote_id' => "{$name}-{$i}",
                    'channel' => LeadConversation::CHANNEL_MESSAGE,
                    'participant' => $s[0],
                    'occurred_at' => Carbon::parse($s[1]),
                    'is_automatic' => $s[2] ?? false,
                    'text' => $s[3] ?? 'Can someone look at my fireplace?',
                ]);
            }

            return $lead;
        });
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.follow_up_due')->get()->all();
    }

    /** Production's state (desk, 2026-11-02): after an automatic reply LeadThread leaves last_contact_from on the customer. */
    public function test_a_lead_whose_last_human_word_was_the_customers_is_followed_up(): void
    {
        $quiet = $this->lead('Dana Whitfield', [[LeadConversation::FROM_CONSUMER, '2026-09-30T14:00:00Z'],
            [LeadConversation::FROM_ADVERTISER, '2026-09-30T14:00:30Z', true]]);
        $this->inTenant($this->t['org'], fn () => $quiet->forceFill(['last_contact_from' => LeadConversation::FROM_CONSUMER])->save());

        $this->artisan('ezbizi:leads-follow-up-due')->assertSuccessful();

        $this->assertSame([$quiet->id], array_map(fn ($e) => $e->subject_id, $this->raised()));
    }

    public function test_a_quiet_customer_is_followed_up_once(): void
    {
        $C = LeadConversation::FROM_CONSUMER;
        $A = LeadConversation::FROM_ADVERTISER;

        $quiet = $this->lead('Dana Whitfield', [[$C, '2026-09-30T14:00:00Z'], [$A, '2026-09-30T14:00:30Z', true]]);
        $this->lead('Person', [[$C, '2026-09-30T14:00:00Z'], [$A, '2026-09-30T14:00:30Z', true], [$A, '2026-09-30T14:20:00Z', false]]);
        $this->lead('Answered', [[$C, '2026-09-30T14:00:00Z'], [$A, '2026-09-30T14:00:30Z', true], [$C, '2026-09-30T14:10:00Z'], [$A, '2026-09-30T14:11:00Z', true]]);
        $this->lead('Early', [[$C, '2026-09-30T15:30:00Z'], [$A, '2026-09-30T15:30:30Z', true]]);           // 1.5 h
        $this->lead('Yesterday', [[$C, '2026-09-29T20:00:00Z'], [$A, '2026-09-29T20:00:30Z', true]]);      // 16:00 NY yesterday
        $this->lead('Upset', [[$C, '2026-09-30T14:00:00Z', false, 'I want to cancel my appointment'], [$A, '2026-09-30T14:00:30Z', true]]);
        $this->lead('Rodrigo era', [[$C, '2026-09-30T14:00:00Z'], [$A, '2026-09-30T14:00:30Z', false]]);   // sent outside EzBizi

        $this->artisan('ezbizi:leads-follow-up-due')->assertSuccessful();

        $events = $this->raised();
        $this->assertCount(1, $events);
        $this->assertSame($quiet->id, $events[0]->subject_id);
        $this->assertSame('Hi Dana', $events[0]->payload['after']['greeting']);
        // ActionRunner reads {{greeting}} off the lead before the payload: a Lead
        // method named greeting() was called as a relation and threw (health 11-16).
        $this->assertNull($quiet->getAttribute('greeting'));
        $this->assertSame('ls_text', $events[0]->payload['after']['kind']);
        $this->assertFalse($events[0]->payload['after']['not_booked_call']);

        Carbon::setTestNow('2026-09-30T19:00:00Z');
        $this->artisan('ezbizi:leads-follow-up-due')->assertSuccessful();
        $ids = array_map(fn ($e) => $e->subject_id, $this->raised());
        $this->assertSame(1, count(array_keys($ids, $quiet->id)), 'never twice');
        $this->assertCount(2, $ids, 'and Early is due now, 3.5 hours on');
    }

    /** Jose, 2026-11-05: "send only one" - a rule can tell the after-call text already went. */
    public function test_it_says_when_the_call_was_not_booked(): void
    {
        $caller = $this->lead('Pat Caller', [[LeadConversation::FROM_CONSUMER, '2026-09-30T14:00:00Z'],
            [LeadConversation::FROM_ADVERTISER, '2026-09-30T14:00:30Z', true]]);
        $this->inTenant($this->t['org'], fn () => AutomationEvent::create(['organization_id' => $this->t['org']->id,
            'event_key' => 'lead.call_not_booked', 'subject_type' => $caller->getMorphClass(), 'subject_id' => $caller->id,
            'payload' => [], 'occurred_at' => now(), 'available_at' => now()]));

        $this->artisan('ezbizi:leads-follow-up-due')->assertSuccessful();

        $this->assertTrue($this->raised()[0]->payload['after']['not_booked_call']);
    }

    public function test_first_name(): void
    {
        $this->assertSame('Mark', Lead::firstNameOf('mark Morrow'));
        $this->assertNull(Lead::firstNameOf('+1 (202) 250-1749'));
        $this->assertNull(Lead::firstNameOf(null));
    }

    public function test_source_name_is_the_word_a_person_reads(): void
    {
        $this->assertSame('Local Services', (new Lead(['source' => 'local_services']))->source_name);
        $this->assertSame('Thumbtack', (new Lead(['source' => 'thumbtack']))->source_name);
        $this->assertSame('Chat', (new Lead(['source' => 'website_chat']))->source_name);
    }

    public function test_the_auto_reply_greets_by_name_and_signs_with_the_company(): void
    {
        $this->assertSame('Hi Candy, thank you', (new Lead(['contact_name' => 'candy Peterson']))->thank_you);
        $this->assertSame('Thank you', (new Lead(['contact_name' => null]))->thank_you);

        $lead = $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id,
            'source' => Lead::SOURCE_THUMBTACK,
            'remote_id' => 'n-1',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]));
        $this->assertSame($this->t['company']->name, $this->inTenant($this->t['org'], fn () => $lead->fresh()->company_name));
    }

    /**
     * A Local Services account connected at franchise level files its leads
     * with a franchise and no company, and the reply named the organization
     * instead of the brand the customer called.
     */
    public function test_a_franchise_lead_signs_with_its_company_not_the_organization(): void
    {
        $lead = $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id,
            'franchise_id' => $this->t['franchise']->id,
            'source' => Lead::SOURCE_THUMBTACK,
            'remote_id' => 'n-2',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]));

        $this->assertSame($this->t['company']->name, $this->inTenant($this->t['org'], fn () => $lead->fresh()->company_name));
    }
}
