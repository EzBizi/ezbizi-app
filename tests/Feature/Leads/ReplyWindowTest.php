<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-09: an Instagram enquiry two days old said only "This enquiry
 * cannot be replied to right now". The 24-hour window is Meta's rule; the
 * screen now says so, says how long is left, and offers WhatsApp a template.
 */
class ReplyWindowTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function lead(array $t, string $source, ?string $connectionId, string $lastWrote, string $remote = 'psid-1'): Lead
    {
        return $this->inTenant($t['org'], function () use ($t, $source, $connectionId, $lastWrote, $remote) {
            $lead = Lead::query()->create([
                'organization_id' => $t['org']->id, 'source' => $source, 'remote_id' => $remote,
                'connection_id' => $connectionId, 'contact_name' => 'Saman Shafqat', 'phone' => '+13055550199',
            ]);
            LeadConversation::create([
                'organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => 'm-'.Str::random(6),
                'channel' => LeadConversation::CHANNEL_MESSAGE, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now()->sub($lastWrote), 'text' => 'Hi, do you sweep chimneys?',
            ]);

            return $lead;
        });
    }

    private function account(array $t, string $provider, array $scopes = []): SocialConnection
    {
        return $this->inTenant($t['org'], fn () => tap(SocialConnection::create([
            'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
            'provider' => $provider, 'remote_id' => 'page-1', 'display_name' => 'A1 Chimney',
        ]), fn ($c) => $c->forceFill(['credentials' => ['access_token' => 'page-token', 'scopes' => $scopes]])->save()));
    }

    private function detail(array $t, Lead $lead): array
    {
        return $this->signedInAs($t['admin'])->getJson('/api/v1/leads/'.$lead->id)->assertOk()->json('data');
    }

    public function test_an_instagram_message_two_days_old_says_why_in_plain_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ig = $this->account($t, SocialProvider::InstagramProfessional->value);
        $lead = $this->lead($t, Lead::SOURCE_INSTAGRAM, $ig->id, '2 days');

        $data = $this->detail($t, $lead);

        $this->assertFalse($data['can_reply']);
        $this->assertStringContainsString("Instagram only allows replies within 24 hours of the person's last message", $data['cannot_reply_because']);
        $this->assertStringContainsString('2 days ago', $data['cannot_reply_because']);
        $this->assertNotNull($data['reply_window_ends_at']);
    }

    public function test_an_open_window_says_when_it_closes_on_the_thread_and_the_list(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->account($t, SocialProvider::FacebookPage->value);
        $lead = $this->lead($t, Lead::SOURCE_FACEBOOK, $page->id, '3 hours');

        $data = $this->detail($t, $lead);
        $this->assertTrue($data['can_reply']);
        $ends = \Illuminate\Support\Carbon::parse($data['reply_window_ends_at']);
        $this->assertEqualsWithDelta(21 * 3600, now()->diffInSeconds($ends), 120);

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'))->firstWhere('id', $lead->id);
        $this->assertSame($data['reply_window_ends_at'], $row['reply_window_ends_at']);
    }

    public function test_a_disconnected_page_and_a_grant_without_messenger_each_say_so(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $gone = $this->lead($t, Lead::SOURCE_FACEBOOK, null, '1 hour', 'psid-gone');
        $this->assertStringContainsString('is no longer connected', $this->detail($t, $gone)['cannot_reply_because']);

        $page = $this->account($t, SocialProvider::FacebookPage->value, ['pages_show_list', 'pages_manage_posts']);
        $lead = $this->lead($t, Lead::SOURCE_FACEBOOK, $page->id, '1 hour', 'psid-2');
        $data = $this->detail($t, $lead);
        $this->assertFalse($data['can_reply']);
        $this->assertStringContainsString('not given Messenger access', $data['cannot_reply_because']);
    }

    public function test_with_human_agent_a_person_answers_for_seven_days_and_a_rule_still_only_one(): void
    {
        config(['services.meta.human_agent' => true]);
        Http::fake(['graph.instagram.com/*' => Http::response(['message_id' => 'mid.late'])]);
        $t = $this->provisionTenant('A1-Group');
        $ig = $this->account($t, SocialProvider::InstagramProfessional->value);
        $lead = $this->lead($t, Lead::SOURCE_INSTAGRAM, $ig->id, '2 days');

        $this->assertTrue($this->detail($t, $lead)['can_reply']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->id.'/messages', ['text' => 'Sorry for the wait - yes, we sweep chimneys.'])
            ->assertCreated();
        Http::assertSent(fn (Request $r) => $r['messaging_type'] === 'MESSAGE_TAG' && $r['tag'] === 'HUMAN_AGENT');

        // A rule is not a human agent.
        $this->expectExceptionMessage('Meta only allows a reply within 24 hours');
        $this->inTenant($t['org'], fn () => app(\App\Support\Leads\LeadReplies::class)->for($lead->fresh())->send($lead->fresh(), 'Auto', automatic: true));
    }

    public function test_a_closed_whatsapp_window_offers_an_approved_template_and_sends_it(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'message_templates')
            ? Http::response(['data' => [[
                'name' => 'booking_link', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'en_US',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, book here: {{2}}']],
            ]]])
            : Http::response(['messages' => [['id' => 'wamid.T1']]]));
        $t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'phone_number_id' => '1309270845610102',
            'waba_id' => '1090541950244647', 'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lead = $this->lead($t, Lead::SOURCE_WHATSAPP, null, '2 days', '13055550199');
        $this->inTenant($t['org'], fn () => \App\Models\WhatsAppContact::query()->create([
            'organization_id' => $t['org']->id, 'phone_number_id' => '1309270845610102', 'counterparty' => '13055550199',
            'kind' => \App\Models\WhatsAppContact::KIND_LEAD, 'lead_id' => $lead->id,
        ]));

        $data = $this->detail($t, $lead);
        $this->assertStringContainsString('you can send an approved template now', $data['cannot_reply_because']);
        $this->assertEquals([['name' => 'booking_link', 'preview' => 'Hi {{1}}, book here: {{2}}', 'parameters' => 2]], $data['whatsapp_templates']);

        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->id.'/whatsapp-template', ['template' => 'booking_link', 'variables' => ['Saman']])
            ->assertUnprocessable()->assertJsonPath('message', 'This template needs 2 values.');
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->id.'/whatsapp-template', ['template' => 'booking_link', 'variables' => ['Saman', 'https://ezbizi.com/b/1']])
            ->assertCreated();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '1309270845610102/messages') && $r['template']['name'] === 'booking_link');
    }
}
