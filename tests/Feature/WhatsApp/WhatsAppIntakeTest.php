<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadReplies;
use App\Support\Settings\SettingsResolver;
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
