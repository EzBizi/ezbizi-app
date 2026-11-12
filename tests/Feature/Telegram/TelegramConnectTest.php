<?php

namespace Tests\Feature\Telegram;

use App\Models\SocialConnection;
use App\Models\TelegramLink;
use App\Support\Telegram\TelegramApi;
use App\Support\Telegram\TelegramBotStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * An organization's own Telegram bot on Settings › Integrations › Messaging
 * (Jose, 2026-10-26): connect by token, webhook routed by its own secret, the
 * env bot kept for TELEGRAM_ORG_ID alone, disconnect and linked staff.
 */
class TelegramConnectTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private const TOKEN = '111111:AAbbCCddEEffGGhhIIjjKKllMMnnOOppQQ';

    private const ENV_TOKEN = '999999:ENVenvENVenvENVenvENVenvENVenv';

    /** @var list<array{url: string, data: array<string, mixed>}> */
    private array $calls = [];

    private bool $tokenOk = true;

    private string $lastError = '';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => null,
            'services.telegram.webhook_secret' => null,
            'services.telegram.org_id' => null,
        ]);

        Http::fake(function (Request $request) {
            $this->calls[] = ['url' => $request->url(), 'data' => $request->data()];
            $method = substr($request->url(), strrpos($request->url(), '/') + 1);

            if ($method === 'getMe') {
                return $this->tokenOk
                    ? Http::response(['ok' => true, 'result' => ['id' => 111111, 'is_bot' => true, 'first_name' => 'A1 Tech', 'username' => 'a1_tech_bot']])
                    : Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
            }
            if ($method === 'getWebhookInfo') {
                return Http::response(['ok' => true, 'result' => ['url' => route('telegram.webhook'), 'last_error_message' => $this->lastError]]);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });
    }

    private function called(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_ends_with($c['url'], '/'.$method)));
    }

    private function connect(array $t)
    {
        return $this->signedInAs($t['admin'])->postJson('/api/v1/social/telegram', ['bot_token' => self::TOKEN]);
    }

    private function row(array $t): ?SocialConnection
    {
        return SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $t['org']->id)->where('provider', 'telegram')->first();
    }

    private function link(array $t, int $from): string
    {
        $id = (string) Str::uuid();
        DB::table('telegram_links')->insert([
            'id' => $id, 'organization_id' => $t['org']->id, 'user_id' => $t['admin']->id,
            'telegram_user_id' => $from, 'chat_id' => $from, 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function update(string $secret, int $from = 4242)
    {
        return $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Tech'],
            'chat' => ['id' => $from, 'type' => 'private'], 'date' => time(), 'text' => '/start',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    // ── connecting ───────────────────────────────────────────────

    public function test_connecting_verifies_the_token_sets_the_webhook_with_a_generated_secret_and_stores_it_encrypted(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->connect($t)
            ->assertCreated()
            ->assertJsonPath('data.provider', 'telegram')
            ->assertJsonPath('data.display_name', 'A1 Tech')
            ->assertJsonPath('data.handle', '@a1_tech_bot');

        $this->assertCount(1, $this->called('getMe'));
        $set = $this->called('setWebhook');
        $this->assertCount(1, $set);
        $this->assertStringContainsString('/bot'.self::TOKEN.'/setWebhook', $set[0]['url']);
        $this->assertSame(route('telegram.webhook'), $set[0]['data']['url']);
        $secret = (string) $set[0]['data']['secret_token'];
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $secret);

        $row = $this->row($t);
        $this->assertSame('111111', $row->remote_id);
        $this->assertSame('organization', $row->scope_type);
        $this->assertSame(self::TOKEN, $row->credentials['bot_token']);
        $this->assertSame($secret, $row->credentials['webhook_secret']);
        // Encrypted at rest: neither value is in the stored column.
        $raw = (string) DB::table('social_connections')->where('id', $row->id)->value('credentials');
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }

    public function test_a_bad_token_is_refused_with_a_sentence_and_nothing_is_saved(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->tokenOk = false;

        $this->connect($t)->assertStatus(422)
            ->assertJsonPath('errors.bot_token.0', 'Telegram did not accept that token. Copy it again from @BotFather, or send /token there for a new one.');

        $this->signedInAs($t['admin'])->postJson('/api/v1/social/telegram', ['bot_token' => 'not a token'])
            ->assertStatus(422)->assertJsonValidationErrors('bot_token');

        $this->assertNull($this->row($t));
        $this->assertCount(0, $this->called('setWebhook'));
    }

    public function test_connecting_needs_the_ai_assistance_manage_grant(): void
    {
        $t = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($t, 'Viewer', ['ai_assistance.view', 'marketing.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/social/telegram', ['bot_token' => self::TOKEN])->assertForbidden();
        $this->signedInAs($viewer)->getJson('/api/v1/social/telegram/links')->assertForbidden();
        $this->assertCount(0, $this->calls);

        $manager = $this->userWithRole($t, 'Manager', ['ai_assistance.manage']);
        $this->signedInAs($manager)->postJson('/api/v1/social/telegram', ['bot_token' => self::TOKEN])->assertCreated();
    }

    public function test_one_bot_cannot_serve_two_organizations(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->connect($alpha)->assertCreated();

        $this->connect($beta)->assertStatus(422)->assertJsonValidationErrors('bot_token');
        $this->assertNull($this->row($beta));
    }

    // ── the webhook ──────────────────────────────────────────────

    public function test_the_webhook_routes_by_the_connection_secret_and_replies_with_that_bot(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->connect($t)->assertCreated();
        $secret = $this->row($t)->credentials['webhook_secret'];
        $this->calls = [];

        $this->update($secret)->assertOk()->assertJsonPath('ok', true);

        $sent = $this->called('sendMessage');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/bot'.self::TOKEN.'/sendMessage', $sent[0]['url']);

        $this->update('wrong-secret')->assertStatus(401);
    }

    public function test_the_env_secret_still_routes_to_telegram_org_id_with_the_env_token(): void
    {
        $t = $this->provisionTenant('A1-Group');
        config([
            'services.telegram.bot_token' => self::ENV_TOKEN,
            'services.telegram.webhook_secret' => 'env-secret',
            'services.telegram.org_id' => $t['org']->id,
        ]);

        $this->update('env-secret')->assertOk();

        $sent = $this->called('sendMessage');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/bot'.self::ENV_TOKEN.'/sendMessage', $sent[0]['url']);
    }

    public function test_organizations_are_isolated_by_their_bots(): void
    {
        $env = $this->provisionTenant('A1-Group');
        $beta = $this->provisionTenant('Beta');
        config([
            'services.telegram.bot_token' => self::ENV_TOKEN,
            'services.telegram.webhook_secret' => 'env-secret',
            'services.telegram.org_id' => $env['org']->id,
        ]);
        $this->connect($beta)->assertCreated();
        $betaSecret = $this->row($beta)->credentials['webhook_secret'];

        // Beta's staff link answers on Beta's bot only; the env org's link is
        // not found through Beta's secret.
        $this->link($env, 7001);
        $this->calls = [];
        $this->update($betaSecret, 7001)->assertOk();
        $sent = $this->called('sendMessage');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/bot'.self::TOKEN.'/', $sent[0]['url']);
        $this->assertStringContainsString('share your phone number', $sent[0]['data']['text']);

        // The env token is never used for Beta, and Beta's for nobody else.
        $this->assertNull(TelegramApi::forOrganization($this->provisionTenant('Gamma')['org']->id));
        $this->assertTrue(TelegramApi::configuredFor($env['org']->id));

        // Beta's links list does not show the env organization's staff.
        $this->signedInAs($beta['admin'])->getJson('/api/v1/social/telegram/links')->assertOk()->assertJsonCount(0, 'data');
    }

    // ── the card ─────────────────────────────────────────────────

    public function test_the_card_shows_the_bot_and_needs_attention_on_a_webhook_error(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->connect($t)->assertCreated();
        $this->link($t, 5001);

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections?include=data_sources')->assertOk()->json('data'))
            ->firstWhere('provider', 'telegram');
        $this->assertSame('connected', $row['status']);
        $this->assertSame('@a1_tech_bot · 1 staff member linked', $row['detail']);

        $this->lastError = 'Wrong response from the webhook: 502 Bad Gateway';
        TelegramBotStatus::forget($this->row($t));

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections?include=data_sources')->json('data'))
            ->firstWhere('provider', 'telegram');
        $this->assertSame('broken', $row['status']);
        $this->assertStringContainsString('502 Bad Gateway', $row['status_reason']);
    }

    // ── disconnecting ────────────────────────────────────────────

    public function test_disconnecting_needs_a_code_lists_linked_staff_and_deletes_the_webhook(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->connect($t)->assertCreated();
        $row = $this->row($t);
        $this->link($t, 5001);
        $this->link($t, 5002);

        $dependents = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/integrations/social/'.$row->id.'/dependents')->assertOk()->json('data.dependents'))->keyBy('key');
        $this->assertSame(2, $dependents['telegram_links']['count']);

        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/connections/'.$row->id)->assertStatus(422);
        $this->assertNotNull($this->row($t));
        $this->assertCount(0, $this->called('deleteWebhook'));

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/connections/'.$row->id, $code)->assertOk();
        $this->assertNull($this->row($t));
        $deleted = $this->called('deleteWebhook');
        $this->assertCount(1, $deleted);
        $this->assertStringContainsString('/bot'.self::TOKEN.'/deleteWebhook', $deleted[0]['url']);
    }

    // ── linked staff ─────────────────────────────────────────────

    public function test_linked_staff_are_listed_and_unlinked_with_an_audit_row(): void
    {
        $t = $this->provisionTenant('Alpha');
        $id = $this->link($t, 5001);

        $this->signedInAs($t['admin'])->getJson('/api/v1/social/telegram/links')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha Admin');

        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/telegram/links/'.$id)->assertOk();

        $this->assertSame(0, TelegramLink::query()->withoutGlobalScopes()->count());
        $this->assertTrue(DB::table('audit_events')->where('action', 'telegram_link.unlinked')->exists());
    }

    public function test_unlink_is_scoped_to_the_organization_and_gated(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $id = $this->link($alpha, 5001);

        $this->signedInAs($beta['admin'])->deleteJson('/api/v1/social/telegram/links/'.$id)->assertNotFound();

        $viewer = $this->userWithRole($alpha, 'Viewer', ['ai_assistance.view']);
        $this->signedInAs($viewer)->deleteJson('/api/v1/social/telegram/links/'.$id)->assertForbidden();

        $this->assertSame(1, TelegramLink::query()->withoutGlobalScopes()->count());
    }
}
