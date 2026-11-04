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
 * Account - one to one, held behind the report_ready template for anybody
 * outside the 24-hour window.
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

        $byTo = collect($this->sent)->keyBy('to');
        $this->assertSame('NJ: 50 jobs', $byTo['13055550101']['text']['body']);
        $this->assertSame('automation_alert', $byTo['13055550102']['template']['name']);
        $this->assertSame('A1 NJ Same-Day Report', $byTo['13055550102']['template']['components'][0]['parameters'][0]['text']);
        $this->assertCount(2, $this->sent);

        $this->assertSame(
            ['13055550102'],
            WhatsAppPendingReport::query()->withoutGlobalScopes()->whereNull('delivered_at')->pluck('counterparty')->all(),
        );
    }

    /** 2026-11-04: Technician sales was refused, "text.body must be at most 4096 characters". */
    public function test_a_long_text_goes_in_pieces_with_one_notice(): void
    {
        $group = $this->group();
        $recent = $this->member($group, '(305) 555-0101');
        $quiet = $this->member($group, '(305) 555-0102');
        $this->inTenant($this->t['org'], fn () => WhatsAppMessage::create([
            'phone_number_id' => self::NUMBER, 'direction' => WhatsAppMessage::DIRECTION_IN,
            'counterparty' => '13055550101', 'status' => WhatsAppMessage::STATUS_RECEIVED, 'kind' => 'text', 'body' => 'hi',
        ]));
        $body = implode("\n", array_fill(0, 300, str_repeat('x', 29))); // 300 lines, 8,999 characters

        $this->inTenant($this->t['org'], function () use ($recent, $quiet, $body) {
            $number = WhatsAppNumber::query()->firstOrFail();
            app(WhatsAppGroups::class)->deliverTextToUser($number, $recent, 'Technician sales', $body);
            app(WhatsAppGroups::class)->deliverTextToUser($number, $quiet, 'Technician sales', $body);
        });

        $toRecent = collect($this->sent)->where('to', '13055550101');
        $this->assertCount(3, $toRecent);
        $this->assertTrue($toRecent->every(fn ($m) => mb_strlen($m['text']['body']) <= 4000));
        $this->assertSame($body, $toRecent->pluck('text.body')->implode("\n"));
        $this->assertCount(1, collect($this->sent)->where('to', '13055550102'), 'one notice');
        $this->assertSame(3, WhatsAppPendingReport::query()->withoutGlobalScopes()->where('counterparty', '13055550102')->count());
    }

    public function test_show_report_delivers_what_was_held_and_nothing_else(): void
    {
        $group = $this->group();
        $this->member($group, '(305) 555-0102');
        $this->deliver();
        $this->sent = [];

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
