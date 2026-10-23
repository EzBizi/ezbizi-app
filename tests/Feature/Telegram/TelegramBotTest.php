<?php

namespace Tests\Feature\Telegram;

use App\Jobs\AnswerTelegramWithTech;
use App\Models\AssistantAnswer;
use App\Models\TelegramLink;
use App\Support\Telegram\TelegramApi;
use App\Support\Telegram\TelegramStaffBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The staff Tech Assistant on Telegram. */
class TelegramBotTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SECRET = 'telegram-secret-for-tests';

    private const FROM = 555001;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<string> */
    private array $urls = [];

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('A1-Group');
        config([
            'services.telegram.bot_token' => '123:TESTTOKEN',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.org_id' => $this->t['org']->id,
            'services.grok_cli.home' => '/var/lib/ezbizi-grok',
        ]);
        Http::fake(function ($request) {
            $this->urls[] = $request->url();
            if (str_ends_with($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_id' => $request->data()['file_id'], 'file_path' => 'photos/plate.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_ends_with($request->url(), '/sendMessage')) {
                $this->sent[] = $request->data();
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => count($this->sent)]]);
        });
        $this->inTenant($this->t['org'], fn () => $this->t['admin']->forceFill(['phone' => '(786) 776-2666', 'is_active' => true])->save());
    }

    private function update(array $message, string $secret = self::SECRET, int $from = self::FROM)
    {
        return $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Tech'],
            'chat' => ['id' => $from, 'type' => 'private'], 'date' => time(), ...$message,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    private function linkAdmin(): void
    {
        DB::table('telegram_links')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'user_id' => $this->t['admin']->id,
            'telegram_user_id' => self::FROM, 'chat_id' => self::FROM, 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function links(): int
    {
        return TelegramLink::query()->withoutGlobalScopes()->count();
    }

    public function test_a_bad_secret_is_rejected(): void
    {
        Process::fake();
        $this->update(['text' => '/start'], 'wrong')->assertStatus(401);
        $this->postJson('/api/v1/telegram/webhook', ['message' => []])->assertStatus(401);

        $this->assertSame([], $this->sent);
    }

    public function test_an_unlinked_user_is_asked_for_their_phone_and_their_own_contact_links_them(): void
    {
        $this->update(['text' => '/start'])->assertOk();
        $this->assertSame(TelegramStaffBot::SHARE, $this->sent[0]['reply_markup']['keyboard'][0][0]['text']);
        $this->assertTrue($this->sent[0]['reply_markup']['keyboard'][0][0]['request_contact']);

        // A forwarded card: somebody else's number. Refused.
        $this->update(['contact' => ['phone_number' => '+17867762666', 'first_name' => 'Boss', 'user_id' => 999]])->assertOk();
        $this->assertSame(0, $this->links());

        $this->update(['contact' => ['phone_number' => '17867762666', 'first_name' => 'Tech', 'user_id' => self::FROM]])->assertOk();
        $link = TelegramLink::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$this->t['admin']->id, $this->t['org']->id, self::FROM], [$link->user_id, $link->organization_id, $link->chat_id]);
        $this->assertStringContainsString('Linked', end($this->sent)['text']);
    }

    public function test_non_staff_are_refused(): void
    {
        $this->update(['contact' => ['phone_number' => '+15550001111', 'user_id' => self::FROM]])->assertOk();

        $this->assertSame(0, $this->links());
        $this->assertStringContainsString('this bot is for A1 staff', $this->sent[0]['text']);
    }

    public function test_a_text_answer_is_stored_with_channel_telegram(): void
    {
        $this->linkAdmin();
        $this->grok("I'm not ready to approve ordering this part yet.\nMISSING: serial");

        $this->update(['text' => 'valve for majestic insert, LP i think'])->assertOk();

        $answer = $this->inTenant($this->t['org'], fn () => AssistantAnswer::sole());
        $this->assertSame('telegram', $answer->channel);
        $this->assertSame('telegram', $this->inTenant($this->t['org'], fn () => $answer->job->channel));
        $this->assertStringContainsString("I'm not ready to approve ordering", end($this->sent)['text']);
    }

    public function test_new_job_resets_the_job(): void
    {
        $this->linkAdmin();
        Process::fake();

        $this->update(['text' => 'new job'])->assertOk();

        Process::assertNothingRan();
        $this->assertStringContainsString('New job started', end($this->sent)['text']);
    }

    public function test_the_largest_photo_is_downloaded_and_passed_to_the_assistant(): void
    {
        $this->linkAdmin();
        Bus::fake([AnswerTelegramWithTech::class]);

        $this->update(['photo' => [
            ['file_id' => 'SMALL', 'file_size' => 100, 'width' => 90, 'height' => 60],
            ['file_id' => 'BIG', 'file_size' => 90000, 'width' => 1280, 'height' => 960],
            ['file_id' => 'MID', 'file_size' => 9000, 'width' => 320, 'height' => 240],
        ]])->assertOk();

        $this->assertStringContainsString('Reading that photo', $this->sent[0]['text']);
        Bus::assertDispatched(AnswerTelegramWithTech::class, fn ($job) => $job->fileId === 'BIG' && $job->connection === 'database-long');

        $commands = [];
        $this->grok("MODEL: 6000C\nORDER CHECK: DO NOT ORDER YET", $commands);
        (new AnswerTelegramWithTech($this->t['admin']->id, self::FROM, '', 'BIG'))->handle(app(TelegramStaffBot::class), app(\App\Support\Tenancy\TenantContext::class));

        $this->assertContains('https://api.telegram.org/file/bot123:TESTTOKEN/photos/plate.jpg', $this->urls);
        $this->assertContains('read_file', $commands[0]);
        $this->assertStringContainsString('MODEL: 6000C', end($this->sent)['text']);
        $job = $this->inTenant($this->t['org'], fn () => AssistantAnswer::sole()->job);
        $this->assertSame(['6000C', 'NG'], [$job->model, $job->fuel]);
    }

    public function test_a_long_reply_is_split(): void
    {
        $this->linkAdmin();
        $this->grok(str_repeat("NOTE: check the pilot assembly first.\n", 200));

        $this->update(['text' => 'pilot for heat & glo 6000C'])->assertOk();

        $this->assertCount(2, $this->sent);
        $this->assertLessThanOrEqual(TelegramApi::MAX_TEXT, mb_strlen($this->sent[0]['text']));
        $this->assertSame(trim(str_repeat("NOTE: check the pilot assembly first.\n", 200)), $this->sent[0]['text']."\n".$this->sent[1]['text']);
    }

    public function test_voice_and_pdf_get_the_whatsapp_replies(): void
    {
        $this->linkAdmin();
        $this->update(['voice' => ['file_id' => 'V1', 'duration' => 3]])->assertOk();
        $this->update(['document' => ['file_id' => 'D1', 'file_name' => 'manual.pdf', 'mime_type' => 'application/pdf']])->assertOk();

        $this->assertStringContainsString('Please type it', $this->sent[0]['text']);
        $this->assertStringContainsString('Manuals', $this->sent[1]['text']);
    }

    public function test_unlink_removes_the_link(): void
    {
        $this->linkAdmin();

        $this->update(['text' => '/unlink'])->assertOk();

        $this->assertSame(0, $this->links());
        $this->assertTrue($this->sent[0]['reply_markup']['remove_keyboard']);
    }

    public function test_more_than_thirty_messages_a_minute_are_dropped(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->update(['text' => 'hello'])->assertOk();
        }

        $this->assertCount(TelegramStaffBot::PER_MINUTE, $this->sent);
    }

    public function test_set_webhook_sends_the_secret_and_messages_only(): void
    {
        $this->artisan('telegram:set-webhook', ['url' => 'https://app.example.com/api/v1/telegram/webhook'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setWebhook')
            && $r['secret_token'] === self::SECRET && $r['allowed_updates'] === ['message']
            && $r['url'] === 'https://app.example.com/api/v1/telegram/webhook');
    }
}
