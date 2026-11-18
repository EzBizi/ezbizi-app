<?php

namespace Tests\Feature\Leads;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Leads › Recovery, the inbox (Jose, 2026-11-16: "clients WILL BE REPLYING to these messages we send out"). */
class RecoveryInboxTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A chat that can always be answered, recording what it sent; the real
        // one needs a live website session. Bound under the key the app tags.
        $this->app->bind(\App\Support\Web\WebsiteChatReplies::class, fn () => new class implements \App\Support\Leads\LeadReplyChannel
        {
            public function source(): string
            {
                return Lead::SOURCE_WEBSITE_CHAT;
            }

            public function canReply(Lead $lead): bool
            {
                return true;
            }

            public function allowsUnattendedReply(): bool
            {
                return true;
            }

            public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
            {
                return LeadConversation::create(['organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                    'channel' => 'MESSAGE', 'participant' => LeadConversation::FROM_ADVERTISER, 'occurred_at' => now(), 'text' => $text, 'is_automatic' => $automatic]);
            }
        });
    }

    private function chat(array $t, string $name): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => uniqid(), 'contact_name' => $name, 'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));
    }

    private function send(array $t, Lead $lead, string $trigger, ?array $conditions, string $name): void
    {
        $this->inTenant($t['org'], function () use ($t, $lead, $trigger, $conditions, $name) {
            $rule = Automation::create(['organization_id' => $t['org']->id, 'name' => $name, 'trigger_key' => $trigger, 'is_active' => true]);
            $rule->addVersion($conditions, [['type' => 'reply', 'message' => 'Book here, or reply to this text.']], $t['admin']);
            $event = (new AutomationEvent)->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => $t['org']->id, 'event_key' => $trigger,
                'subject_type' => Lead::class, 'subject_id' => $lead->id, 'payload' => []]);
            $result = app(ActionRunner::class)->run(['type' => 'reply', 'message' => 'Book here, or reply to this text.'], $event, $lead, automation: $rule->fresh());
            $this->assertTrue($result['ok'] && empty($result['skipped']), json_encode($result));
        });
    }

    public function test_a_recovery_text_puts_the_lead_in_recovery_with_its_rule_named_and_a_first_reply_does_not(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $won = $this->chat($t, 'Won Back');
        $first = $this->chat($t, 'First Reply');
        $this->send($t, $won, 'lead.call_not_booked', null, 'Not booked - after a call');
        $this->send($t, $first, 'lead.received', ['all' => [['op' => 'eq', 'field' => 'office_open', 'value' => true]]], 'Chat - Initial Auto Reply');

        $this->assertNotNull($this->inTenant($t['org'], fn () => $won->fresh()->recovery_at));
        $this->assertNull($this->inTenant($t['org'], fn () => $first->fresh()->recovery_at), 'an open-office first reply is not recovery');

        $as = $this->signedInAs($t['admin']);
        $list = $as->getJson('/api/v1/leads?source=recovery')->assertOk();
        $this->assertSame(['Won Back'], array_column($list->json('data'), 'contact_name'));
        $this->assertSame(1, $list->json('meta.unread_by_source.recovery'));

        // Still in All, carrying its mark.
        $all = collect($as->getJson('/api/v1/leads')->assertOk()->json('data'))->keyBy('contact_name');
        $this->assertNotNull($all['Won Back']['recovery_at']);
        $this->assertNull($all['First Reply']['recovery_at']);

        // The thread names the rule that wrote it.
        $sent = $this->inTenant($t['org'], fn () => LeadConversation::query()->where('lead_id', $won->id)->sole());
        $this->assertSame('Not booked - after a call', $sent->automation_name);
        $this->assertTrue($sent->is_automatic);
    }

    /** Jose, 2026-11-16 (John Sczepanski): the same address spelled another way is not "a different address". */
    public function test_the_same_address_spelled_differently_is_not_offered(): void
    {
        $this->assertTrue(Lead::sameAddress('1734 Hallman Dr, Blue bell PA 19422', '1734 Hallman Dr, Blue Bell, PA 19422, USA'));
        $this->assertTrue(Lead::sameAddress('1734 Hallman Drive, Blue Bell, PA 19422-1234', '1734 Hallman Dr, Blue Bell, PA 19422, USA'));
        $this->assertFalse(Lead::sameAddress('1736 Hallman Dr, Blue Bell PA 19422', '1734 Hallman Dr, Blue Bell, PA 19422, USA'), 'another house');
        $this->assertFalse(Lead::sameAddress('1734 Hallman Dr, Ambler PA 19002', '1734 Hallman Dr, Blue Bell, PA 19422, USA'), 'another town');

        $t = $this->provisionTenant('A1-Group');
        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => uniqid(), 'contact_name' => 'John Sczepanski', 'phone' => '+16104202617', 'status' => Lead::STATUS_NEW,
            'received_at' => now(), 'address' => '1734 Hallman Dr, Blue Bell, PA 19422, USA',
        ]));
        $contacts = \App\Console\Commands\LeadsRead::contacts($lead, ['address' => '1734 Hallman Dr, Blue bell PA 19422']);
        $this->assertSame([], $contacts['suggest']);

        // A reading saved before the fix still carries the offer: the lead no longer shows it.
        $this->inTenant($t['org'], fn () => $lead->forceFill(['ai_set' => ['reading' => ['suggest' => ['address' => '1734 Hallman Dr, Blue bell PA 19422']]]])->save());
        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->assertJsonPath('data.reading.suggest', null);
    }
}
