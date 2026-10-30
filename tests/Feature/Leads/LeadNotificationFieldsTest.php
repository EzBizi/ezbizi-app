<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadThread;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-06, "Source Names for notification": the lead's notification
 * words, and no "reply" for another call on a Local Services call lead.
 */
class LeadNotificationFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        app(TenantContext::class)->set($this->t['org']);
    }

    private function lead(string $type): Lead
    {
        return Lead::create([
            'organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id,
            'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => uniqid(),
            'lead_type' => $type,
            'phone' => '+13192102005',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);
    }

    private function said(Lead $lead, string $channel, array $fields = []): void
    {
        app(LeadThread::class)->store($lead, uniqid(), [
            'channel' => $channel,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(),
            ...$fields,
        ]);
    }

    private function raised(string $key): int
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', $key)->count();
    }

    public function test_a_second_call_on_a_call_lead_is_not_a_reply(): void
    {
        $call = $this->lead('PHONE_CALL');
        $this->said($call, LeadConversation::CHANNEL_PHONE_CALL, ['duration_ms' => 70000]);
        $this->said($call, LeadConversation::CHANNEL_PHONE_CALL, ['duration_ms' => 5000]);

        $this->assertSame(2, $this->raised('lead.contact_received'));
        $this->assertSame(0, $this->raised('lead.customer_replied'));

        $text = $this->lead('MESSAGE');
        $this->said($text, LeadConversation::CHANNEL_MESSAGE, ['text' => 'Hi']);
        $this->said($text, LeadConversation::CHANNEL_MESSAGE, ['text' => 'Still there?']);

        $this->assertSame(1, $this->raised('lead.customer_replied'), 'a text lead still has replies');
    }

    /** Jose, 2026-10-30: the closed-office text went out after an answered call on a text lead. */
    public function test_an_answered_call_on_a_text_lead_is_not_a_reply_and_a_missed_one_is(): void
    {
        $text = $this->lead('MESSAGE');
        $this->said($text, LeadConversation::CHANNEL_MESSAGE, ['text' => 'Hi']);
        $this->said($text, LeadConversation::CHANNEL_PHONE_CALL, ['duration_ms' => 229000]);

        $this->assertSame(0, $this->raised('lead.customer_replied'), 'an answered call was handled');

        $this->said($text, LeadConversation::CHANNEL_PHONE_CALL, ['duration_ms' => 0]);

        $this->assertSame(1, $this->raised('lead.customer_replied'), 'a missed call still waits for an answer');
    }

    /** Jose, 2026-10-09: a customer who writes again is not left hidden. */
    public function test_writing_again_brings_a_set_aside_lead_back(): void
    {
        foreach ([Lead::STATUS_ARCHIVED, Lead::STATUS_NOT_A_LEAD, Lead::STATUS_LOST, Lead::STATUS_BOOKED] as $status) {
            $lead = $this->lead('MESSAGE');
            $lead->forceFill(['status' => $status])->save();
            $this->said($lead, LeadConversation::CHANNEL_MESSAGE, ['text' => 'Hi again']);
            $this->assertSame($status === Lead::STATUS_BOOKED ? Lead::STATUS_BOOKED : Lead::STATUS_NEW, $lead->fresh()->status, $status);
        }
    }

    public function test_the_notification_words(): void
    {
        $call = $this->lead('PHONE_CALL');
        $this->said($call, LeadConversation::CHANNEL_PHONE_CALL, ['duration_ms' => 70000]);
        $call = $call->fresh();

        $this->assertSame('New "Local Services Call" lead', $call->lead_headline);
        $this->assertSame('(319) 210-2005 - 1:10 min - '.$this->t['company']->name, $call->lead_details);
        $this->assertSame('', $call->reply_headline);

        $text = $this->lead('MESSAGE');
        $this->said($text, LeadConversation::CHANNEL_MESSAGE, ['text' => str_repeat('word ', 20)]);
        $text = $text->fresh();

        $this->assertSame('New reply on "Local Services Text"', $text->reply_headline);
        $this->assertSame(55, mb_strlen($text->reply_preview));
        $this->assertSame('(319) 210-2005 - '.$this->t['company']->name, $text->lead_details, 'a text has no duration');
    }
}
