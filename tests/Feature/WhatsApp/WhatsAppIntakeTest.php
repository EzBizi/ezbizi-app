<?php

namespace Tests\Feature\WhatsApp;

use App\Http\Controllers\Api\WhatsAppMediaController;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadReplies;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\LeadWhatsApp;
use App\Support\Social\WhatsAppIntake;
use App\Support\Social\WhatsAppReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A stranger's first WhatsApp message (Jose, 2026-10-06): asked "I need a
 * service" / "I want to work with you"; the tap makes a lead or tells HR, and
 * until then the business hears of an "(Unknown)" message.
 */
class WhatsAppIntakeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BOT = '1309270845610102';

    private const SECRET = 'app-secret-from-meta';

    private const STRANGER = '13055550123';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.whatsapp_token' => 'a-token-for-the-fake',
            'services.meta.whatsapp_bot_number_id' => self::BOT,
        ]);

        $n = 0;
        Http::fake(function ($request) use (&$n) {
            // The approved list LeadWhatsApp renders a sent template from.
            if (str_contains($request->url(), 'message_templates')) {
                return Http::response(['data' => [[
                    'name' => 'booking_confirmation', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'en_US',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, see you {{2}}.']],
                ]]]);
            }

            $n++;
            $this->sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.REPLY'.$n]]]);
        });

        $this->t = $this->provisionTenant('A1-Group');

        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id,
            'phone_number_id' => self::BOT, 'waba_id' => '1090541950244647',
            'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_first_message_asks_the_question_and_tells_leads_of_an_unknown_message(): void
    {
        $leads = $this->userWithRole($this->t, 'Leads desk', ['leads.view']);
        $hr = $this->userWithRole($this->t, 'Hiring', ['hr.create']);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'Do you sweep chimneys in Miami?']], 'wamid.IN1')->assertOk();

        $rows = collect($this->sent[0]['interactive']['action']['sections'][0]['rows'])->pluck('title', 'id')->all();
        $this->assertSame([WhatsAppIntake::LEAD => 'I need a service', WhatsAppIntake::HIRING => 'I want to work with you'], $rows);

        $this->assertSame(['New "WhatsApp" message (Unknown)'], $this->titlesFor($leads->id));
        $this->assertSame([], $this->titlesFor($hr->id));
        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());

        // Asked once: their next message is answered by the bot, not asked again.
        $this->inbound(['type' => 'text', 'text' => ['body' => 'hello?']], 'wamid.IN2');
        $this->assertCount(1, $this->titlesFor($leads->id));
    }

    /** Leads, 2026-10-29: a hidden number rings nobody, because the bell carries the number. */
    public function test_a_hidden_number_rings_no_bell(): void
    {
        $leads = $this->userWithRole($this->t, 'Leads desk', ['leads.view']);
        DB::table('lead_hidden_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id,
            'digits' => substr(self::STRANGER, -10), 'created_at' => now(), 'updated_at' => now()]);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'private']], 'wamid.IN1')->assertOk();

        $this->assertSame([], $this->titlesFor($leads->id));
    }

    public function test_i_need_a_service_makes_one_lead_carrying_what_they_first_said(): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => 'Do you sweep chimneys in Miami?']], 'wamid.IN1');
        $this->inbound($this->tap(WhatsAppIntake::LEAD, 'I need a service'), 'wamid.IN2')->assertOk();
        $this->inbound($this->tap(WhatsAppIntake::LEAD, 'I need a service'), 'wamid.IN3');

        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame(Lead::SOURCE_WHATSAPP, $lead->source);
        $this->assertSame('+'.self::STRANGER, $lead->phone);
        $this->assertSame('Do you sweep chimneys in Miami?', $lead->summary);
        $this->assertSame($this->t['org']->id, $lead->organization_id);
        $this->assertSame(
            ['Do you sweep chimneys in Miami?'],
            LeadConversation::query()->withoutGlobalScopes()->where('lead_id', $lead->id)->pluck('text')->all(),
        );
        $this->assertSame(1, AutomationEvent::query()->withoutGlobalScopes()
            ->where('event_key', 'lead.received')->where('subject_id', $lead->id)->count());
        // Both taps were thanked: the second was answered, not refused by the
        // database for trying to make the same lead again.
        $this->assertCount(2, array_filter($this->sent, fn ($m) => str_contains((string) ($m['text']['body'] ?? ''), 'Someone will reach out')));
    }

    public function test_a_number_with_auto_reply_off_sends_nothing_and_every_message_lands_on_one_lead(): void
    {
        DB::table('whatsapp_numbers')->where('phone_number_id', self::BOT)->update(['auto_reply' => false]);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'Do you sweep chimneys in Miami?']], 'wamid.IN1')->assertOk();
        $this->inbound(['type' => 'text', 'text' => ['body' => 'hello?']], 'wamid.IN2')->assertOk();

        $this->assertSame([], $this->sent);
        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame(
            ['Do you sweep chimneys in Miami?', 'hello?'],
            LeadConversation::query()->withoutGlobalScopes()->where('lead_id', $lead->id)->orderBy('created_at')->pluck('text')->all(),
        );
    }

    /** A-1, 2026-10-29: Jose's own test text went to the staff bot, not Leads. */
    public function test_a_number_with_auto_reply_off_has_no_staff_bot_even_for_staff(): void
    {
        DB::table('whatsapp_numbers')->where('phone_number_id', self::BOT)->update(['auto_reply' => false]);
        $staff = $this->userWithRole($this->t, 'Leads desk', ['leads.view']);
        DB::table('users')->where('id', $staff->id)->update(['phone' => '(305) 555-0123']);
        // The tenant's own number, not EzBizi's bot number: the webhook routed only the bot's.
        config(['services.meta.whatsapp_bot_number_id' => '999999999']);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'hello']], 'wamid.IN1')->assertOk();

        $this->assertSame([], $this->sent);
        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['hello'], LeadConversation::query()->withoutGlobalScopes()->where('lead_id', $lead->id)->pluck('text')->all());
    }

    public function test_i_want_to_work_with_you_tells_hr_and_makes_no_lead(): void
    {
        $leads = $this->userWithRole($this->t, 'Leads desk', ['leads.view']);
        $hr = $this->userWithRole($this->t, 'Hiring', ['hr.create']);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'Hi, are you hiring techs?']], 'wamid.IN1');
        $this->inbound($this->tap(WhatsAppIntake::HIRING, 'I want to work with you'), 'wamid.IN2');

        $this->assertSame(['New "WhatsApp" hiring request'], $this->titlesFor($hr->id));
        $this->assertNotContains('New "WhatsApp" hiring request', $this->titlesFor($leads->id));
        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
    }

    public function test_a_customer_we_already_have_lands_in_their_lead_and_is_not_asked(): void
    {
        // A Thumbtack lead whose booking confirmation went out on WhatsApp; the
        // phone is stored without the country code.
        $lead = $this->inTenant($this->t['org'], fn () => Lead::query()->create([
            'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_THUMBTACK, 'remote_id' => 'neg-1',
            'phone' => '(305) 555-0123', 'phone_digits' => '3055550123', 'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));

        $this->inbound(['type' => 'text', 'text' => ['body' => 'Can you come at 3 instead?']], 'wamid.IN1')->assertOk();
        $this->inbound(['type' => 'text', 'text' => ['body' => 'Thanks!']], 'wamid.IN2')->assertOk();

        $this->assertSame([], $this->sent);   // no question, no menu: staff answer from Leads
        $this->assertSame(1, Lead::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            ['Can you come at 3 instead?', 'Thanks!'],
            LeadConversation::query()->withoutGlobalScopes()->where('lead_id', $lead->id)->orderBy('occurred_at')->orderBy('id')->pluck('text')->all(),
        );
    }

    public function test_a_template_sent_to_a_lead_is_on_its_thread_and_the_reply_comes_back_to_it(): void
    {
        $make = fn (string $id, string $at) => $this->inTenant($this->t['org'], fn () => Lead::query()->create([
            'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_THUMBTACK, 'remote_id' => $id,
            'phone' => '(305) 555-0123', 'phone_digits' => '3055550123', 'status' => Lead::STATUS_NEW, 'received_at' => $at,
        ]));
        $booked = $make('neg-old', '2026-10-01 10:00:00');
        $make('neg-new', '2026-10-10 10:00:00');   // newer, same phone

        $row = $this->inTenant($this->t['org'], fn () => app(LeadWhatsApp::class)
            ->sendTemplate($booked, 'booking_confirmation', ['John', 'Tuesday'], automatic: true));

        // What the customer read, filled in, and marked as the business's.
        $this->assertSame('Hi John, see you Tuesday.', $row->text);
        $this->assertSame(LeadConversation::FROM_ADVERTISER, $row->participant);
        $this->assertSame('booking_confirmation', $this->sent[0]['template']['name']);

        // The reply goes to the booked lead, not the newest one with that phone.
        $this->inbound(['type' => 'text', 'text' => ['body' => 'Perfect, thanks']], 'wamid.IN1')->assertOk();
        $this->assertEqualsCanonicalizing(['Hi John, see you Tuesday.', 'Perfect, thanks'],
            LeadConversation::query()->withoutGlobalScopes()->where('lead_id', $booked->id)->pluck('text')->all());
    }

    public function test_a_whatsapp_lead_is_answered_from_leads_on_the_number_they_wrote_to(): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => 'Do you sweep chimneys in Miami?']], 'wamid.IN1');
        $this->inbound($this->tap(WhatsAppIntake::LEAD, 'I need a service'), 'wamid.IN2');

        $channel = app('lead-reply-channel.whatsapp');
        $lead = $this->inTenant($this->t['org'], fn () => Lead::query()->sole());

        $this->assertTrue(collect(app()->tagged(LeadReplies::TAG))
            ->contains(fn ($c) => $c instanceof WhatsAppReplies));
        $this->inTenant($this->t['org'], function () use ($channel, $lead) {
            $this->assertTrue($channel->canReply($lead));

            $row = $channel->send($lead, 'Yes - Tuesday at 10?');

            $this->assertSame('Yes - Tuesday at 10?', $row->text);
            $this->assertSame(LeadConversation::FROM_ADVERTISER, $row->participant);
        });

        $last = end($this->sent);
        $this->assertSame(self::STRANGER, $last['to']);
        $this->assertSame('Yes - Tuesday at 10?', $last['text']['body']);

        // A day after their last message WhatsApp no longer allows it.
        $this->travel(25)->hours();
        $this->inTenant($this->t['org'], fn () => $this->assertFalse($channel->canReply($lead)));
    }

    public function test_switched_off_a_stranger_gets_the_menu_and_nobody_is_told(): void
    {
        $leads = $this->userWithRole($this->t, 'Leads desk', ['leads.view']);
        $this->inTenant($this->t['org'], fn () => app(SettingsResolver::class)
            ->set('ai_assistance.ask_new_contacts', $this->t['org'], false));

        $this->inbound(['type' => 'text', 'text' => ['body' => 'menu']], 'wamid.IN1');

        $ids = collect($this->sent[0]['interactive']['action']['sections'])->flatMap(fn ($s) => $s['rows'])->pluck('id');
        $this->assertNotContains(WhatsAppIntake::LEAD, $ids);
        $this->assertSame([], $this->titlesFor($leads->id));
        $this->assertSame(0, DB::table('whatsapp_contacts')->count());
    }

    public function test_staff_are_never_asked(): void
    {
        $this->inTenant($this->t['org'], fn () => $this->t['admin']->forceFill(['phone' => '(305) 555-0123'])->save());

        $this->inbound(['type' => 'text', 'text' => ['body' => 'menu']], 'wamid.IN1');

        $ids = collect($this->sent[0]['interactive']['action']['sections'])->flatMap(fn ($s) => $s['rows'])->pluck('id');
        $this->assertNotContains(WhatsAppIntake::LEAD, $ids);
        $this->assertSame(0, DB::table('whatsapp_contacts')->count());
    }

    /** @return list<string> */
    private function titlesFor(string $userId): array
    {
        return DB::table('notifications')->where('user_id', $userId)->orderBy('created_at')->pluck('title')->all();
    }

    private function tap(string $id, string $title): array
    {
        return ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => $title]]];
    }

    /** @param array<string, mixed> $message */
    /** Jose, 2026-10-29: a lead shows who wrote, not only the number, and staff replies from the app show on it. */
    public function test_a_coexistence_lead_is_named_and_shows_replies_sent_from_the_business_app(): void
    {
        DB::table('whatsapp_numbers')->where('phone_number_id', self::BOT)->update(['auto_reply' => false]);
        $this->change(['messages' => [['id' => 'wamid.IN1', 'from' => self::STRANGER, 'type' => 'text', 'text' => ['body' => 'Sweep?'],
            'referral' => ['source_type' => 'ad', 'source_id' => '42', 'headline' => 'Fall sweep']]],
            'contacts' => [['wa_id' => self::STRANGER, 'profile' => ['name' => 'Kel']]]]);

        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame('Kel', $lead->contact_name);

        $this->change(['state_sync' => [['type' => 'contact', 'action' => 'add',
            'contact' => ['full_name' => 'Kelvin Badger', 'phone_number' => '+'.self::STRANGER]]]]);
        $this->assertSame('Kelvin Badger', $lead->fresh()->contact_name);
        $this->assertSame('42', DB::table('whatsapp_contacts')->value('referral') ? json_decode(DB::table('whatsapp_contacts')->value('referral'), true)['source_id'] : null);

        $this->change(['message_echoes' => [['id' => 'wamid.OUT1', 'from' => '18885195311', 'to' => self::STRANGER,
            'timestamp' => '1793300000', 'type' => 'text', 'text' => ['body' => 'Yes, Friday works']]]]);
        $this->assertSame(LeadConversation::FROM_ADVERTISER, LeadConversation::query()->withoutGlobalScopes()->where('remote_id', 'wa_app_wamid.OUT1')->value('participant'));
        $this->assertSame([], $this->sent);
    }

    /** Jose, 2026-10-29: a voice note shows in the thread, as a link streamed from Meta, never a stored file. */
    public function test_a_voice_note_lands_on_the_lead_as_a_streamed_attachment(): void
    {
        DB::table('whatsapp_numbers')->where('phone_number_id', self::BOT)->update(['auto_reply' => false]);
        $this->inbound(['type' => 'audio', 'audio' => ['id' => '777', 'mime_type' => 'audio/ogg']], 'wamid.V1')->assertOk();

        $this->assertSame(['wa-media:'.self::BOT.':777:audio'], LeadConversation::query()->withoutGlobalScopes()->sole()->attachments);
        $url = WhatsAppMediaController::url('wa-media:'.self::BOT.':777:audio');
        $this->assertStringContainsString('/api/v1/whatsapp/media/'.self::BOT.'/777', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->get('/api/v1/whatsapp/media/'.self::BOT.'/777')->assertForbidden();
    }

    /** @param array<string, mixed> $value */
    private function change(array $value)
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => self::BOT], ...$value]]]]]]);

        return $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json)->assertOk();
    }

    private function inbound(array $message, string $wamid)
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::BOT],
            'messages' => [['id' => $wamid, 'from' => self::STRANGER, ...$message]],
        ]]]]]]);

        return $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json);
    }
}
