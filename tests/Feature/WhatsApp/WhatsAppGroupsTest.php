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

    public function test_a_real_group_gets_one_message(): void
    {
        $this->group(['remote_id' => 'GROUP-1']);

        $this->assertSame(1, $this->deliver());

        $this->assertCount(1, $this->sent);
        $this->assertSame('group', $this->sent[0]['recipient_type']);
        $this->assertSame('GROUP-1', $this->sent[0]['to']);
        $this->assertSame('NJ: 50 jobs', $this->sent[0]['text']['body']);
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
            'counterparty' => '13055550101', 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi',
        ]));

        $this->assertSame(2, $this->deliver());

        // 2026-11-09 (Jose: "I want both"): the text, then the PDF; outside the window the text in account_report.
        $messages = collect($this->sent)->filter(fn ($m) => isset($m['to']))->map(fn ($m) => [$m['to'], $m['type'], $m['template']['name'] ?? null])
            ->sortBy(fn ($m) => $m[0])->values()->all(); // each member's in order; members in any
        $this->assertSame([
            ['13055550101', 'text', null],
            ['13055550101', 'template', 'report_statement'],
            ['13055550102', 'template', 'account_report'],
            ['13055550102', 'template', 'report_statement'],
        ], $messages);
        $this->assertSame('NJ: 50 jobs', collect($this->sent)->firstWhere('type', 'text')['text']['body']);
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

    /** 2026-11-09: 93 of 107 report texts sat behind "reply to get it"; each now goes as its text and a formatted PDF. */
    public function test_a_report_text_goes_as_its_text_then_a_pdf_in_the_window_or_not(): void
    {
        $group = $this->group();
        $recent = $this->member($group, '(305) 555-0101');
        $quiet = $this->member($group, '(305) 555-0102');
        $this->inTenant($this->t['org'], fn () => WhatsAppMessage::create([
            'phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_IN,
            'counterparty' => '13055550101', 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi',
        ]));
        $body = "\u{1F4B5} *Revenue collected*\n_Mon, Nov 9, 2026 · payments received_\n\n".str_repeat("• *A-1 Chimney · New Jersey*: $17,744.11\n", 30)."\n*TOTAL: $532,323.30*";

        $this->inTenant($this->t['org'], function () use ($recent, $quiet, $body) {
            $number = WhatsAppNumber::query()->firstOrFail();
            $this->assertTrue(app(WhatsAppGroups::class)->deliverTextToUser($number, $recent, 'Revenue collected · Nov 9', $body, 'report.text:R:revenue_collected:2026-11-09:U'));
            $this->assertTrue(app(WhatsAppGroups::class)->deliverTextToUser($number, $quiet, 'Revenue collected · Nov 9', $body));
        });

        $sent = collect($this->sent)->filter(fn ($m) => isset($m['to']))->values();
        // In the window: the text as written, then the PDF.
        $this->assertSame($body, $sent[0]['text']['body']);
        $this->assertSame('report_statement', $sent[1]['template']['name']);
        $this->assertSame(['type' => 'document', 'document' => ['id' => 'MEDIA-1', 'filename' => 'revenue-collected-nov-9.pdf']],
            $sent[1]['template']['components'][0]['parameters'][0]);
        $this->assertSame('Revenue collected · Nov 9', $sent[1]['template']['components'][1]['parameters'][0]['text']);
        // Outside it: the text on one line in account_report, in order over as many as it needs, then the PDF.
        $texts = $sent->slice(2, -1)->values();
        $this->assertSame(['account_report'], $texts->pluck('template.name')->unique()->values()->all());
        $this->assertGreaterThan(1, $texts->count());
        $this->assertSame('Revenue collected · Nov 9 (1/'.$texts->count().')', $texts[0]['template']['components'][0]['parameters'][0]['text']);
        $values = $texts->map(fn ($m) => $m['template']['components'][0]['parameters'][1]['text']);
        $this->assertStringStartsWith("\u{1F4B5} *Revenue collected* | _Mon, Nov 9, 2026 · payments received_ • *A-1 Chimney · New Jersey*: $17,744.11 •", $values[0]);
        $this->assertStringEndsWith('| *TOTAL: $532,323.30*', $values->last());
        $values->each(fn ($v) => $this->assertTrue(mb_strlen($v) <= 800 && ! preg_match('/[\n\t]| {4}/', $v), $v));
        $this->assertSame('report_statement', $sent->last()['template']['name']);
        $this->assertSame(0, WhatsAppPendingReport::query()->withoutGlobalScopes()->count());
        $this->assertSame(['report.text:R:revenue_collected:2026-11-09:U', 'report.text:R:revenue_collected:2026-11-09:U:text:1'],
            WhatsAppMessage::query()->withoutGlobalScopes()->where('counterparty', '13055550101')->where('direction', WhatsAppMessage::DIRECTION_OUT)
                ->orderBy('idempotency_key')->pluck('idempotency_key')->all());
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
