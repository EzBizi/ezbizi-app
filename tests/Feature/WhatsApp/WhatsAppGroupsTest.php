<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppPendingReport;
use App\Support\Social\WhatsAppGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reports to "the NJ daily group" (docs/REPLACING-RODRIGO-BOT.md): one message
 * to a real WhatsApp group, or - until the number is an Official Business
 * Account - one to one: the text (a template outside the 24-hour window),
 * then the report as a PDF.
 */
class WhatsAppGroupsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const NUMBER = '1309270845610102';

    private const SECRET = 'app-secret-from-meta';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.whatsapp_token' => 'a-token-for-the-fake',
            'services.meta.whatsapp_bot_number_id' => self::NUMBER,
        ]);

        $n = 0;
        Http::fake(function ($request) use (&$n) {
            $n++;
            $this->sent[] = ['url' => $request->url(), 'method' => $request->method(), ...$request->data()];

            return match (true) {
                str_ends_with($request->url(), '/groups') => Http::response(['id' => 'GROUP-1']),
                str_ends_with($request->url(), '/invite_link') => Http::response(['invite_link' => 'https://chat.whatsapp.com/ABC']),
                str_ends_with($request->url(), '/media') => Http::response(['id' => 'MEDIA-1']),
                default => Http::response(['messages' => [['id' => 'wamid.OUT'.$n]]]),
            };
        });

        $this->t = $this->provisionTenant('A1-Group');

        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id,
            'phone_number_id' => self::NUMBER, 'waba_id' => '1090541950244647',
            'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 2026-11-10 (Jose: "just ONE message, just the message + the attachment"): a real group gets the PDF, the report its caption. */
    public function test_a_real_group_gets_one_message_the_pdf_under_its_text(): void
    {
        $this->group(['remote_id' => 'GROUP-1']);

        $this->assertSame(1, $this->deliver());

        $posts = collect($this->sent)->filter(fn ($m) => isset($m['to']))->values();
        $this->assertSame([['GROUP-1', 'group', 'document']], $posts->map(fn ($m) => [$m['to'], $m['recipient_type'], $m['type']])->all());
        $this->assertSame("*A1 NJ Same-Day Report*\n\nNJ: 50 jobs", $posts[0]['document']['caption']);
    }

    public function test_without_a_group_each_member_gets_it_one_to_one(): void
    {
        $group = $this->group();
        $recent = $this->member($group, '(305) 555-0101');
        $quiet = $this->member($group, '(305) 555-0102');
        $this->member($group, '(305) 555-0103', active: false);
        $this->member($group, '');

        // Only the first wrote to the line today.
        $this->inTenant($this->t['org'], fn () => WhatsAppMessage::create([
            'phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_IN,
            'counterparty' => '13055550101', 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi', 'wamid' => 'wamid.IN1',
        ]));

        $this->assertSame(2, $this->deliver());

        // 2026-11-10 (Jose): ONE message each, the PDF under the report; outside the window the document template, the report its value.
        $messages = collect($this->sent)->filter(fn ($m) => isset($m['to']))->map(fn ($m) => [$m['to'], $m['type'], $m['template']['name'] ?? null])
            ->sortBy(fn ($m) => $m[0])->values()->all();
        $this->assertSame([
            ['13055550101', 'document', null],
            ['13055550102', 'template', 'account_report_pdf'],
        ], $messages);
        $this->assertSame("*A1 NJ Same-Day Report*\n\nNJ: 50 jobs", collect($this->sent)->firstWhere('type', 'document')['document']['caption']);
        $this->assertSame([['type' => 'text', 'text' => 'A1 NJ Same-Day Report'], ['type' => 'text', 'text' => 'NJ: 50 jobs']],
            collect(collect($this->sent)->firstWhere('type', 'template')['template']['components'])->firstWhere('type', 'body')['parameters']);
        $this->assertCount(1, collect($this->sent)->filter(fn ($m) => str_ends_with($m['url'], '/media')), 'one upload for every member');
        $this->assertSame(0, WhatsAppPendingReport::query()->withoutGlobalScopes()->count());
    }

    public function test_an_approval_prompt_goes_as_text_alone(): void
    {
        $group = $this->group();
        $this->member($group, '(305) 555-0102');

        $this->assertSame(1, $this->inTenant($this->t['org'],
            fn () => app(WhatsAppGroups::class)->deliver('daily:NJ', 'Weekly P&L', 'Reply *APPROVE*', file: false)));

        $this->assertSame(['account_report'], collect($this->sent)->pluck('template.name')->all());
    }

    /** Bar, 2026-11-04..09 (131047): only a message Meta delivered opens the window, and Meta's refusal shuts it. */
    public function test_only_meta_opens_the_window_and_its_refusal_shuts_it(): void
    {
        $group = $this->group();
        $ours = $this->member($group, '(305) 555-0101');
        $refused = $this->member($group, '(305) 555-0102');
        $this->inTenant($this->t['org'], function () {
            $in = fn (string $from, ?string $wamid) => WhatsAppMessage::create(['phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_IN,
                'counterparty' => $from, 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi', 'wamid' => $wamid]);
            $in('13055550101', null);
            $in('13055550102', 'wamid.IN2');
            (new WhatsAppMessage)->forceFill(['phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_OUT, 'counterparty' => '13055550102',
                'status' => WhatsAppMessage::STATUS_FAILED, 'status_reason' => 'Re-engagement message', 'kind' => 'text', 'body' => 'x'])->save();
        });

        $this->inTenant($this->t['org'], function () use ($ours, $refused) {
            $number = WhatsAppNumber::query()->firstOrFail();
            app(WhatsAppGroups::class)->deliverTextToUser($number, $ours, 'Revenue collected · Nov 9', 'NJ: $1');
            app(WhatsAppGroups::class)->deliverTextToUser($number, $refused, 'Revenue collected · Nov 9', 'NJ: $1');
        });

        $this->assertSame(['template'], collect($this->sent)->filter(fn ($m) => isset($m['to']))->pluck('type')->unique()->values()->all());
    }

    /** 2026-11-10: a report is one message in the window or not; one over WhatsApp's 1024-character caption says its headings and totals. */
    public function test_a_long_report_is_one_message_with_its_headings_and_totals_in_the_window_or_not(): void
    {
        $group = $this->group();
        $recent = $this->member($group, '(305) 555-0101');
        $quiet = $this->member($group, '(305) 555-0102');
        $this->inTenant($this->t['org'], fn () => WhatsAppMessage::create([
            'phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_IN,
            'counterparty' => '13055550101', 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi', 'wamid' => 'wamid.IN1',
        ]));
        $body = "\u{1F4B5} *Revenue collected*\n_Mon, Nov 9, 2026 · payments received_\n\n".str_repeat("• *A-1 Chimney · New Jersey*: $17,744.11\n", 30)."\n*TOTAL: $532,323.30*";

        $this->inTenant($this->t['org'], function () use ($recent, $quiet, $body) {
            $number = WhatsAppNumber::query()->firstOrFail();
            $this->assertTrue(app(WhatsAppGroups::class)->deliverTextToUser($number, $recent, 'Revenue collected · Nov 9', $body, 'report.text:R:revenue_collected:2026-11-09:U'));
            $this->assertTrue(app(WhatsAppGroups::class)->deliverTextToUser($number, $quiet, 'Revenue collected · Nov 9', $body));
        });

        $sent = collect($this->sent)->filter(fn ($m) => isset($m['to']))->values();
        $this->assertCount(2, $sent);
        // In the window: the PDF, its caption the title, the headings and the total.
        $this->assertSame(['id' => 'MEDIA-1', 'filename' => 'revenue-collected-nov-9.pdf',
            'caption' => "*Revenue collected · Nov 9*\n\n\u{1F4B5} *Revenue collected*\n*TOTAL: $532,323.30*\n\n_Full detail in the PDF_"], $sent[0]['document']);
        // Outside it: the document template, the title and the same on one line its values.
        $this->assertSame('account_report_pdf', $sent[1]['template']['name']);
        $this->assertSame([['type' => 'text', 'text' => 'Revenue collected · Nov 9'],
            ['type' => 'text', 'text' => "\u{1F4B5} *Revenue collected* | *TOTAL: $532,323.30* | _Full detail in the PDF_"]],
            collect($sent[1]['template']['components'])->firstWhere('type', 'body')['parameters']);
        $this->assertSame(0, WhatsAppPendingReport::query()->withoutGlobalScopes()->count());
        $this->assertSame(['report.text:R:revenue_collected:2026-11-09:U'],
            WhatsAppMessage::query()->withoutGlobalScopes()->where('counterparty', '13055550101')->where('direction', WhatsAppMessage::DIRECTION_OUT)
                ->pluck('idempotency_key')->all());
    }

    public function test_show_report_delivers_what_was_held_and_nothing_else(): void
    {
        // Held before 2026-11-09, when reports stopped waiting behind a notice.
        $this->inTenant($this->t['org'], fn () => WhatsAppPendingReport::create(['organization_id' => $this->t['org']->id,
            'phone_number_id' => self::NUMBER, 'counterparty' => '13055550102', 'body' => 'NJ: 50 jobs']));

        $this->inbound(['type' => 'button', 'button' => ['text' => 'Show report', 'payload' => 'Show report']])->assertOk();

        $this->assertCount(1, $this->sent); // the report, and no bot menu after it
        $this->assertSame('NJ: 50 jobs', $this->sent[0]['text']['body']);
        $this->assertSame(0, WhatsAppPendingReport::query()->withoutGlobalScopes()->whereNull('delivered_at')->count());
    }

    public function test_making_the_group_keeps_its_id_and_invite_link(): void
    {
        $group = $this->group();

        $this->inTenant($this->t['org'], fn () => app(WhatsAppGroups::class)->create($group));

        $group->refresh();
        $this->assertSame('GROUP-1', $group->remote_id);
        $this->assertSame('https://chat.whatsapp.com/ABC', $group->invite_link);
        $this->assertSame('approval_required', $this->sent[0]['join_approval_mode']);
    }

    /** Jose, 2026-11-11: an automation's WhatsApp to a person goes from the Reports number as a report, never from Main. */
    public function test_an_automation_message_to_a_person_goes_from_the_reports_number(): void
    {
        $user = $this->userWithRole($this->t, 'Manager', ['leads.view']);
        $this->inTenant($this->t['org'], function () use ($user) {
            $user->forceFill(['phone' => '(305) 555-0101'])->save();
            DB::table('whatsapp_numbers')->update(['purpose' => 'support']);
            DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'phone_number_id' => 'REPORTS-1',
                'waba_id' => 'W2', 'display_number' => '+18774508572', 'purpose' => 'reports', 'created_at' => now(), 'updated_at' => now()]);
            $rule = \App\Models\Automation::create(['name' => 'Digest', 'trigger_key' => 'lead.daily_digest', 'is_active' => false, 'created_by' => $this->t['admin']->id]);

            $done = app(\App\Support\Automations\ActionRunner::class)->run(['type' => 'whatsapp.send', 'template' => 'automation_alert', 'from' => '+19545162455',
                'to_field' => 'user:'.$user->id, 'parameters' => ['Morning leads digest', '{{summary_lines}}']],
                new \App\Models\AutomationEvent(['event_key' => 'lead.daily_digest', 'payload' => ['after' => ['summary_lines' => "Calls: 3\nBooked: 1"]]]), null, automation: $rule);

            $this->assertTrue($done['ok'], $done['detail']);
        });

        $out = collect($this->sent)->filter(fn ($m) => $m['method'] === 'POST');
        $this->assertNotEmpty($out);
        $this->assertTrue($out->every(fn ($m) => str_contains($m['url'], '/REPORTS-1/')), 'every call from the Reports number');
    }

    private function deliver(): int
    {
        return $this->inTenant($this->t['org'],
            fn () => app(WhatsAppGroups::class)->deliver('daily:NJ', 'A1 NJ Same-Day Report', 'NJ: 50 jobs'));
    }

    private function group(array $attributes = []): WhatsAppGroup
    {
        return $this->inTenant($this->t['org'], fn () => WhatsAppGroup::create([
            'phone_number_id' => self::NUMBER, 'purpose' => 'daily:NJ', 'subject' => 'A1 NJ - Daily Performance', ...$attributes,
        ]));
    }

    private function member(WhatsAppGroup $group, string $phone, bool $active = true)
    {
        $user = $this->userWithRole($this->t, 'Manager '.Str::random(4), ['leads.view']);

        $this->inTenant($this->t['org'], function () use ($group, $user, $phone, $active) {
            $user->forceFill(['phone' => $phone, 'is_active' => $active])->save();
            $group->members()->attach($user->id, ['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id]);
        });

        return $user;
    }

    /** @param array<string, mixed> $message */
    private function inbound(array $message)
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::NUMBER],
            'messages' => [['id' => 'wamid.IN'.Str::random(6), 'from' => '13055550102', ...$message]],
        ]]]]]]);

        return $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json);
    }
}
