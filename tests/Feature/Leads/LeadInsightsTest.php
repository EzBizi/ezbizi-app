<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Services\Leads\LeadInsights;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What a model can say about an enquiry, and what it may never do.
 *
 * Item 5 of the a1tools list: a draft reply the desk reads and sends itself,
 * and a proofread it may take or leave. Sorting into BOOK / SUPPORT / ARCHIVE
 * is ezbizi:leads-read's (LeadsReadTest). Off until LEADS_AI_GROK is on;
 * nothing on screen depends on it.
 */
class LeadInsightsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Grok answering from a queue, one structured answer per question, no CLI. */
    private function on(array $answers): void
    {
        config(['ezbizi.leads_ai_grok' => true, 'services.ai.provider' => 'grok']);
        $this->app->instance(\App\Support\Ai\GrokCliProvider::class, new class($answers) implements \App\Support\Ai\AiProvider
        {
            public function __construct(private array $answers) {}

            public function name(): string { return 'grok'; }

            public function configured(): bool { return true; }

            public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
            {
                $key = array_key_first($schema['properties']);

                return array_map(fn () => [$key => array_shift($this->answers)], $prompts);
            }
        });
    }

    private function off(): void
    {
        config(['ezbizi.leads_ai_grok' => false]);
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

    public function test_with_no_keys_nothing_is_classified_and_nothing_is_offered(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->off();
        $lead = $this->lead($t);

        $this->inTenant($t['org'], function () use ($lead) {
            $threads = app(\App\Support\Leads\LeadThread::class);
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

    /** Phone's texts have no lead: the same fix from POST /proofread. */
    public function test_a_text_with_no_lead_is_proofread_too(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->on(["Yes, I'll call you right away"]);

        $this->signedInAs($t['admin'])->postJson('/api/v1/proofread', ['text' => 'Yes, I call you right away'])
            ->assertOk()->assertJsonPath('data.suggestion', "Yes, I'll call you right away");
    }

    /** 11-10: 10 x 504 while Grok had its 300 s default; a proofread gives up first. */
    public function test_a_proofread_on_grok_gives_up_inside_the_gateway_limit(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        config(['ezbizi.leads_ai_grok' => true, 'services.ai.provider' => 'grok']);
        $grok = new class implements \App\Support\Ai\AiProvider
        {
            public array $timeouts = [];

            public function name(): string { return 'grok'; }

            public function configured(): bool { return true; }

            public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
            {
                $this->timeouts[] = $timeout;

                return [new \RuntimeException('timed out')];
            }
        };
        $this->app->instance(\App\Support\Ai\GrokCliProvider::class, $grok);
        $lead = $this->lead($t);

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/proofread", ['text' => 'Yes, I call you right away'])
            ->assertOk()->assertJsonPath('data.suggestion', null);
        $this->assertSame([LeadInsights::PROOFREAD_TIMEOUT], $grok->timeouts);
        $this->assertLessThan(60, LeadInsights::PROOFREAD_TIMEOUT);
    }
}
