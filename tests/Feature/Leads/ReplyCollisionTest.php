<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadReplyChannel;
use App\Support\Leads\LeadThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20: two people answered one customer four times in under a minute. */
class ReplyCollisionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_second_replier_sees_who_is_replying_and_is_stopped_until_they_say_send_anyway(): void
    {
        $t = $this->provisionTenant('A1-Group');
        // Any channel will do: this is about who sends, not where it goes.
        $this->app->bind('lead-reply-channel.whatsapp', fn () => new class implements LeadReplyChannel
        {
            public function source(): string { return Lead::SOURCE_WHATSAPP; }

            public function canReply(Lead $lead): bool { return true; }

            public function allowsUnattendedReply(): bool { return true; }

            public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
            {
                app(LeadThread::class)->store($lead, 'sent_'.uniqid(), ['channel' => 'MESSAGE', 'participant' => 'ADVERTISER',
                    'occurred_at' => now(), 'text' => $text, 'is_automatic' => $automatic]);

                return LeadConversation::query()->where('lead_id', $lead->id)->latest('created_at')->latest('id')->firstOrFail();
            }
        });
        $lead = $this->inTenant($t['org'], fn () => Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'source' => Lead::SOURCE_WHATSAPP, 'remote_id' => 'wa1', 'phone' => '+15405551234', 'status' => Lead::STATUS_NEW, 'received_at' => now()]));
        $ara = $this->userWithRole($t, 'Desk A', ['leads.view', 'leads.edit', 'leads.whatsapp.view']);
        $this->inTenant($t['org'], fn () => $ara->forceFill(['name' => 'Ara Awe'])->save());
        $tiara = $this->userWithRole($t, 'Desk B', ['leads.view', 'leads.edit', 'leads.whatsapp.view']);
        $seen = now()->subSecond()->toIso8601String();

        // Ara starts typing: Tiara, viewing, is told.
        $this->signedInAs($ara)->postJson("/api/v1/leads/{$lead->id}/typing")->assertOk();
        $this->assertSame('Ara Awe', $this->signedInAs($tiara)->getJson("/api/v1/leads/{$lead->id}")->json('data.replying'));
        $this->assertNull($this->signedInAs($ara)->getJson("/api/v1/leads/{$lead->id}")->json('data.replying'), 'not to herself');

        // Ara sends; the lead becomes hers, and the hold ends.
        $this->travel(2)->seconds();
        $this->signedInAs($ara)->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => "Hi! We can come Friday.\nDoes 9 work?", 'seen_at' => $seen])->assertCreated();
        $this->assertSame('Ara Awe', $this->inTenant($t['org'], fn () => $lead->refresh()->dispatcher));
        $this->assertNull($this->signedInAs($tiara)->getJson("/api/v1/leads/{$lead->id}")->json('data.replying'));

        // Tiara, who opened it before Ara's message, is stopped - then sends anyway.
        $this->signedInAs($tiara)->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => 'Hello!', 'seen_at' => $seen])
            ->assertStatus(409)->assertJsonPath('message', 'Ara Awe just replied: Hi! We can come Friday.. Send anyway?');
        $this->signedInAs($tiara)->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => 'Hello!', 'seen_at' => $seen, 'force' => true])->assertCreated();
        $this->assertSame('Ara Awe', $this->inTenant($t['org'], fn () => $lead->refresh()->dispatcher), 'still the first to answer');

        // Ara's own earlier message never stops Ara.
        $this->signedInAs($ara)->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => 'One more thing', 'seen_at' => now()->subMinute()->toIso8601String()])
            ->assertStatus(409);   // Tiara's "Hello!" came after; Ara's own did not count
    }
}
