<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Services\Leads\LeadInsights;
use App\Support\Leads\LeadThread;
use App\Support\Settings\SettingsResolver;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What a model can say about an enquiry, and what it may never do.
 *
 * Item 5 of the a1tools list. Classification into BOOK / SUPPORT / ARCHIVE
 * with a one-line summary, once, the first time the enquirer has said
 * something in words; and a draft reply the desk reads and sends itself.
 * Off until AWS keys and a model id exist; nothing on screen depends on it.
 */
class LeadInsightsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A Bedrock client that answers from a queue, no network. */
    private function bedrock(array $answers): BedrockRuntimeClient
    {
        $handler = new MockHandler;

        foreach ($answers as $text) {
            $handler->append(new Result([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => $text]]]],
            ]));
        }

        return new BedrockRuntimeClient([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler'     => $handler,
        ]);
    }

    private function on(array $answers): void
    {
        $this->app->instance(LeadInsights::class, new LeadInsights($this->bedrock($answers), 'test-model'));
    }

    private function off(): void
    {
        $this->app->instance(LeadInsights::class, new LeadInsights(null, ''));
    }

    private function lead(array $t, array $extra = []): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'source'          => 'website_chat',
            'remote_id'       => 'chat-1',
            'lead_type'       => 'MESSAGE',
            'contact_name'    => 'Jane Doe',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now(),
            ...$extra,
        ]));
    }

    public function test_a_lead_is_classified_once_the_enquirer_has_said_something(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->on(['```json'."\n".'{"classification":"book","summary":"Wants the chimney at 14 Ash Lane inspected before winter."}'."\n".'```']);
        $lead = $this->lead($t);

        $this->inTenant($t['org'], function () use ($lead) {
            $threads = app(LeadThread::class);

            // The business speaks first: nothing to read yet.
            $threads->store($lead, 'm-0', ['channel' => 'LIVECHAT', 'participant' => 'ADVERTISER', 'occurred_at' => now()->subMinutes(2), 'text' => 'Hello, how can we help?']);
            $threads->refresh($lead);
            $this->assertNull($lead->fresh()->classification, 'nothing the enquirer said yet');

            $threads->store($lead, 'm-1', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => now()->subMinute(), 'text' => 'Can you inspect my chimney before winter? 14 Ash Lane.']);
            $threads->refresh($lead);
        });

        $fresh = $this->inTenant($t['org'], fn () => $lead->fresh());
        $this->assertSame('BOOK', $fresh->classification, 'the wire word, upper-cased, from a fenced answer');
        $this->assertSame('Wants the chimney at 14 Ash Lane inspected before winter.', $fresh->summary);
        $this->assertNotNull($fresh->classified_at);
        $this->assertSame(Lead::STATUS_NEW, $fresh->status, 'a reading never moves the status');

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertSame('BOOK', $detail['classification']);
    }

    public function test_classification_happens_once_and_a_nonsense_answer_stores_nothing(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->on(['I am not sure what this is.', '{"classification":"ARCHIVE","summary":"Spam."}']);
        $lead = $this->lead($t);

        $this->inTenant($t['org'], function () use ($lead) {
            $threads = app(LeadThread::class);
            $threads->store($lead, 'm-1', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => now(), 'text' => 'hi']);
            $threads->refresh($lead);
            $this->assertNull($lead->fresh()->classification, 'an answer outside the three words is no answer');

            // Second refresh asks again (still unclassified) and gets ARCHIVE.
            $threads->refresh($lead);
            $this->assertSame('ARCHIVE', $lead->fresh()->classification);

            // Third refresh does not ask: the pile a lead landed on stays.
            $threads->refresh($lead);
            $this->assertSame('ARCHIVE', $lead->fresh()->classification);
        });
    }

    public function test_with_no_keys_nothing_is_classified_and_nothing_is_offered(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->off();
        $lead = $this->lead($t);

        $this->inTenant($t['org'], function () use ($lead) {
            $threads = app(LeadThread::class);
            $threads->store($lead, 'm-1', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => now(), 'text' => 'Can you come Saturday?']);
            $threads->refresh($lead);
        });

        $this->assertNull($this->inTenant($t['org'], fn () => $lead->fresh()->classification));

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertFalse($detail['can_suggest']);
        $this->assertNull($detail['classification']);

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/suggest-reply")->assertStatus(422);
    }

    /**
     * A draft, never a send. The endpoint returns text and stores nothing;
     * the conversation is untouched until a person presses Send.
     */
    public function test_a_suggested_reply_is_text_for_the_composer_and_sends_nothing(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->on(['Hi Jane, yes, we can inspect the chimney at 14 Ash Lane. Which day next week suits you?']);
        $lead = $this->lead($t);

        $this->inTenant($t['org'], function () use ($lead) {
            LeadConversation::create([
                'organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => 'm-1',
                'channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => now(),
                'text' => 'Can you inspect my chimney before winter? 14 Ash Lane.',
            ]);
        });

        // website_chat has no reply channel in this test process, so a draft
        // is refused: a draft for a lead you may not answer is noise.
        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/suggest-reply")->assertStatus(422);

        $channel = new class implements \App\Support\Leads\LeadReplyChannel {
            public function source(): string { return 'website_chat'; }
            public function canReply(Lead $lead): bool { return true; }
            public function allowsUnattendedReply(): bool { return true; }
            public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation { throw new \RuntimeException('must not send'); }
        };
        $this->app->instance('test.chat-channel', $channel);
        $this->app->tag(['test.chat-channel'], \App\Support\Leads\LeadReplies::TAG);

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data');
        $this->assertTrue($detail['can_suggest']);

        $draft = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/suggest-reply")
            ->assertOk()
            ->json('data.text');

        $this->assertStringContainsString('14 Ash Lane', $draft);
        $this->assertSame(1, $this->inTenant($t['org'], fn () => LeadConversation::query()->count()), 'a draft stores nothing');
    }

    /** Jose, 2026-11-05: "Yes, I call you right away". Offered, never sent; a setting turns it off. */
    public function test_a_draft_gets_its_clear_fix_and_nothing_when_right_or_switched_off(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->on(["Yes, I'll call you right away", 'See you at 9.']);
        $lead = $this->lead($t);
        $ask = fn (string $text) => $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/proofread", ['text' => $text])->assertOk()->json('data.suggestion');

        $this->assertSame("Yes, I'll call you right away", $ask('Yes, I call you right away'));
        $this->assertNull($ask('See you at 9.'), 'nothing wrong, nothing offered');

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('leads.proofread', $t['org'], false));
        $this->assertNull($ask('Yes, I call you right away'), 'switched off asks nobody');
        $this->assertSame(0, LeadConversation::withoutGlobalScopes()->count(), 'nothing is sent or stored');
    }
}
