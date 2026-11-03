<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\AnswerWhatsAppWithTech;
use App\Jobs\ReadWhatsAppBill;
use App\Models\AssistantAnswer;
use App\Models\WhatsAppNumber;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppBills;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The staff line routing to the Chimney Tech Assistant. */
class TechAssistantWhatsAppTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BOT = '1309270845610102';

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
            'services.meta.whatsapp_bot_number_id' => self::BOT,
            'services.grok_cli.home' => '/var/lib/ezbizi-grok',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/MEDIA1')) {
                return Http::response(['url' => 'https://lookaside.example/plate', 'mime_type' => 'image/jpeg', 'file_size' => 10]);
            }
            if (str_contains($request->url(), 'lookaside.example')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }
            $this->sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.R'.count($this->sent)]]]);
        });

        $this->t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'phone_number_id' => self::BOT,
            'waba_id' => '1090541950244647', 'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->inTenant($this->t['org'], fn () => $this->t['admin']->forceFill(['phone' => '(786) 776-2666', 'is_active' => true])->save());
        DB::table('whatsapp_contacts')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'phone_number_id' => self::BOT,
            'counterparty' => '17867762666', 'kind' => 'lead', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function inbound(array $message, string $wamid = 'wamid.IN1'): void
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::BOT],
            'messages' => [['id' => $wamid, 'from' => '17867762666', ...$message]],
        ]]]]]]);

        $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json)->assertOk();
    }

    private function grok(string $reply, array &$commands = []): void
    {
        Process::fake(function ($process) use ($reply, &$commands) {
            $commands[] = (array) $process->command;

            return Process::result(json_encode(['structuredOutput' => [
                'reply' => $reply,
                'job' => ['brand' => 'Heat & Glo', 'model' => '6000C', 'serial' => 'S1600', 'fuel' => 'NG', 'ignition' => 'IPI'],
                'order_check' => 'not_yet', 'confidence' => 'POSSIBLE', 'part_ids' => [], 'manual_pages' => [],
            ]]));
        });
    }

    public function test_a_parts_question_from_staff_is_answered_by_the_tech_assistant_and_logged(): void
    {
        $this->grok("I'm not ready to approve ordering this part yet.\nMISSING: serial");

        $this->inbound(['type' => 'text', 'text' => ['body' => 'valve for majestic insert, LP i think']]);

        $this->assertStringContainsString("I'm not ready to approve ordering", $this->sent[0]['text']['body']);
        $answer = $this->inTenant($this->t['org'], fn () => AssistantAnswer::sole());
        $this->assertSame('whatsapp', $answer->channel);
        $this->assertSame('not_yet', $answer->order_check);
    }

    public function test_a_staff_photo_goes_to_the_tech_assistant_and_grok_reads_it(): void
    {
        Bus::fake([AnswerWhatsAppWithTech::class]);
        $this->inbound(['type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg']]);

        $this->assertStringContainsString('Reading that photo', $this->sent[0]['text']['body']);
        Bus::assertDispatched(AnswerWhatsAppWithTech::class, fn ($job) => $job->mediaId === 'MEDIA1' && $job->connection === 'database-long');

        // The job's half: the photo is downloaded and handed to Grok as a file.
        $commands = [];
        $this->grok("MODEL: 6000C\nORDER CHECK: DO NOT ORDER YET", $commands);
        $this->inTenant($this->t['org'], fn () => app(WhatsAppStaffBot::class)->techReply(
            WhatsAppNumber::query()->sole(), '17867762666', $this->t['admin'], '', 'MEDIA1',
        ));

        $this->assertContains('read_file', $commands[0]);
        $this->assertStringContainsString('MODEL: 6000C', end($this->sent)['text']['body']);
        $job = $this->inTenant($this->t['org'], fn () => AssistantAnswer::sole()->job);
        $this->assertSame(['6000C', 'S1600', 'NG'], [$job->model, $job->serial, $job->fuel]);
    }

    public function test_a_photo_captioned_bill_keeps_the_bill_flow(): void
    {
        Bus::fake([AnswerWhatsAppWithTech::class, ReadWhatsAppBill::class]);
        $this->assertTrue($this->inTenant($this->t['org'], fn () => $this->t['admin']->hasPermission(WhatsAppBills::PERMISSION)));

        $this->inbound(['type' => 'image', 'image' => ['id' => 'MEDIA1', 'caption' => 'gas bill']]);

        Bus::assertDispatched(ReadWhatsAppBill::class);
        Bus::assertNotDispatched(AnswerWhatsAppWithTech::class);
    }

    public function test_with_tech_mode_off_a_bill_permission_photo_is_a_bill(): void
    {
        Bus::fake([AnswerWhatsAppWithTech::class, ReadWhatsAppBill::class]);
        $this->inTenant($this->t['org'], fn () => app(SettingsResolver::class)->set('ai_assistance.tech_mode', $this->t['org'], false));

        $this->inbound(['type' => 'image', 'image' => ['id' => 'MEDIA1']]);

        Bus::assertDispatched(ReadWhatsAppBill::class);
    }

    public function test_a_voice_note_is_asked_to_be_typed_in_the_persons_language(): void
    {
        Process::fake();
        $this->inbound(['type' => 'text', 'text' => ['body' => 'hola']], 'wamid.A');
        $this->sent = [];
        $this->inbound(['type' => 'audio', 'audio' => ['id' => 'A1']], 'wamid.B');

        $this->assertStringContainsString('escríbelo', $this->sent[0]['text']['body']);
    }

    public function test_a_pdf_is_acknowledged_and_pointed_at_the_manuals_tab(): void
    {
        $this->inbound(['type' => 'document', 'document' => ['id' => 'D1', 'filename' => 'manual.pdf']]);

        $this->assertStringContainsString('AI Assistance', $this->sent[0]['text']['body']);
        $this->assertStringContainsString('Manuals', $this->sent[0]['text']['body']);
    }

    public function test_hello_still_gets_the_menu(): void
    {
        Process::fake();
        $this->inbound(['type' => 'text', 'text' => ['body' => 'hi']]);

        Process::assertNothingRan();
        $this->assertSame('interactive', $this->sent[0]['type']);
    }

    /** Jose, 2026-11-03: parts answers "only Technicians" - whoever holds the Parts tab. */
    public function test_staff_without_the_parts_tab_get_no_parts_answer(): void
    {
        $this->grok('CONFIDENCE: POSSIBLE');
        $this->inTenant($this->t['org'], fn () => $this->t['admin']->forceFill(['phone' => null])->save());
        $dispatcher = $this->userWithRole($this->t, 'Dispatcher', ['customers.view']);
        $this->inTenant($this->t['org'], fn () => $dispatcher->forceFill(['phone' => '(786) 776-2666', 'is_active' => true])->save());

        $this->inbound(['type' => 'text', 'text' => ['body' => 'valve for majestic insert, LP i think']]);

        $this->assertSame(0, AssistantAnswer::query()->withoutGlobalScopes()->count());
    }

    public function test_a_stranger_never_reaches_the_tech_assistant(): void
    {
        Process::fake();
        $this->inTenant($this->t['org'], fn () => $this->t['admin']->forceFill(['phone' => null])->save());

        $this->inbound(['type' => 'text', 'text' => ['body' => 'pilot assy for heat & glo 6000C']]);

        Process::assertNothingRan();
        $this->assertSame(0, AssistantAnswer::query()->withoutGlobalScopes()->count());
    }
}
