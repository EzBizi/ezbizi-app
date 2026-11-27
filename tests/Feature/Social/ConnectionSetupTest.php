<?php

namespace Tests\Feature\Social;

use App\Jobs\OnboardWhatsAppNumber;
use App\Jobs\RegisterThumbtackWebhook;
use App\Jobs\SubscribeMetaMessages;
use App\Jobs\SyncBusinessProfileConnection;
use App\Models\FinancialSource;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\Liveness\Liveness;
use App\Support\Social\SocialProvider;
use App\Support\Telegram\TelegramBotStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-12: "doing a connection should take care of the whole process". */
class ConnectionSetupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t, string $provider, string $remoteId, array $credentials): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId, $credentials) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => $provider, 'remote_id' => $remoteId, 'display_name' => 'A-1 '.$remoteId]);
            $c->connect($credentials);
            $c->save();

            return $c;
        });
    }

    public function test_a_setup_job_that_gives_up_marks_its_card_broken(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $jobs = [
            [SocialProvider::FacebookPage->value, fn (string $id) => new SubscribeMetaMessages($id), 'message subscription'],
            [SocialProvider::Thumbtack->value, fn (string $id) => new RegisterThumbtackWebhook($id), 'webhook'],
            [SocialProvider::GoogleBusinessProfile->value, fn (string $id) => new SyncBusinessProfileConnection($id), 'listings'],
            [SocialProvider::WhatsApp->value, fn (string $id) => new OnboardWhatsAppNumber($id, 'waba1'), 'WhatsApp setup'],
        ];

        foreach ($jobs as $i => [$provider, $make, $words]) {
            $c = $this->connection($t, $provider, 'r'.$i, ['access_token' => 'tok']);
            $make((string) $c->id)->failed(new RuntimeException('Meta said no'));

            $c = SocialConnection::withoutGlobalScopes()->find($c->id);
            $this->assertSame(SocialConnection::STATUS_BROKEN, $c->status, $provider);
            $this->assertStringContainsString($words, (string) $c->status_reason);
        }
    }

    public function test_the_reviews_half_of_a_business_profile_sync_never_marks_the_card(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::GoogleBusinessProfile->value, 'loc', ['access_token' => 'tok']);

        (new SyncBusinessProfileConnection((string) $c->id, true))->failed(new RuntimeException('slow'));

        $this->assertSame(SocialConnection::STATUS_CONNECTED, SocialConnection::withoutGlobalScopes()->find($c->id)->status);
    }

    public function test_a_live_page_without_its_message_subscription_is_subscribed_again(): void
    {
        Queue::fake();
        config(['services.meta.client_id' => 'app1']);
        $t = $this->provisionTenant('A1-Group');
        $missing = $this->connection($t, SocialProvider::FacebookPage->value, 'page1', ['access_token' => 'tok1']);
        $present = $this->connection($t, SocialProvider::FacebookPage->value, 'page2', ['access_token' => 'tok2']);
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'page1/subscribed_apps') => Http::response(['data' => [['id' => 'app1', 'subscribed_fields' => ['feed']]]]),
            str_contains($r->url(), 'page2/subscribed_apps') => Http::response(['data' => [['id' => 'app1', 'subscribed_fields' => ['messages', 'leadgen']]]]),
            default => Http::response(['id' => 'x']),
        });

        $this->assertSame(Liveness::ALIVE, $this->inTenant($t['org'], fn () => app(ConnectionLiveness::class)->check($missing))->verdict);
        $this->inTenant($t['org'], fn () => app(ConnectionLiveness::class)->check($present));

        Queue::assertPushed(SubscribeMetaMessages::class, 1);
        Queue::assertPushed(SubscribeMetaMessages::class, fn ($j) => $j->connectionId === (string) $missing->id);
        $this->assertSame(SocialConnection::STATUS_CONNECTED, SocialConnection::withoutGlobalScopes()->find($missing->id)->status,
            'a missing setup alone never marks the row broken');
    }

    public function test_a_whatsapp_number_with_no_routing_row_is_onboarded_again(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('A1-Group');
        $unrouted = $this->connection($t, SocialProvider::WhatsApp->value, '111', ['access_token' => 'tok', 'waba_id' => 'waba1']);
        $routed = $this->connection($t, SocialProvider::WhatsApp->value, '222', ['access_token' => 'tok', 'waba_id' => 'waba1']);
        $this->inTenant($t['org'], fn () => WhatsAppNumber::query()->create(['organization_id' => $t['org']->id,
            'phone_number_id' => '222', 'waba_id' => 'waba1', 'social_connection_id' => $routed->id]));
        Http::fake(['*' => Http::response(['id' => 'x'])]);

        $this->inTenant($t['org'], fn () => app(ConnectionLiveness::class)->check($unrouted));
        $this->inTenant($t['org'], fn () => app(ConnectionLiveness::class)->check($routed));

        Queue::assertPushed(OnboardWhatsAppNumber::class, 1);
        Queue::assertPushed(OnboardWhatsAppNumber::class, fn ($j) => $j->connectionId === (string) $unrouted->id && $j->wabaId === 'waba1');
    }

    public function test_a_refused_token_reads_back_nothing(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::FacebookPage->value, 'page1', ['access_token' => 'dead']);
        Http::fake(['*' => Http::response(['error' => ['code' => 190]], 400)]);

        $this->inTenant($t['org'], fn () => app(ConnectionLiveness::class)->check($c));

        Queue::assertNothingPushed();
    }

    public function test_each_airtable_base_is_read_with_its_own_connections_token(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, SocialProvider::Airtable->value, 'appAAAAAAAAAAAAAA', ['api_token' => 'pat-a']);
        $this->connection($t, SocialProvider::Airtable->value, 'appBBBBBBBBBBBBBB', ['api_token' => 'pat-b']);
        $this->inTenant($t['org'], function () {
            FinancialSource::query()->create(['base_id' => 'appAAAAAAAAAAAAAA', 'table_id' => 'tblA', 'role' => 'refunds']);
            FinancialSource::query()->create(['base_id' => 'appBBBBBBBBBBBBBB', 'table_id' => 'tblB', 'role' => 'refunds']);
        });
        Http::fake(['*' => Http::response(['tables' => [], 'records' => []])]);

        $this->artisan('ezbizi:airtable-snapshot')->assertSuccessful();

        $tokens = fn (string $base) => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), $base))
            ->map(fn ($p) => $p[0]->header('Authorization')[0] ?? '')->unique()->values()->all();
        $this->assertSame(['Bearer pat-a'], $tokens('appAAAAAAAAAAAAAA'));
        $this->assertSame(['Bearer pat-b'], $tokens('appBBBBBBBBBBBBBB'));
    }

    public function test_a_telegram_bot_whose_webhook_is_gone_is_pointed_back_by_itself(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::Telegram->value, '42', ['bot_token' => '42:abc', 'webhook_secret' => 'sekret']);
        Http::fake([
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->assertNull(TelegramBotStatus::webhookError($c));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === route('telegram.webhook') && $r['secret_token'] === 'sekret');
    }

    public function test_a_telegram_bot_asks_for_a_reconnect_only_when_set_webhook_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::Telegram->value, '42', ['bot_token' => '42:abc', 'webhook_secret' => 'sekret']);
        Http::fake([
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://elsewhere.test/hook']]),
            '*/setWebhook' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $this->assertStringContainsString('Reconnect', (string) TelegramBotStatus::webhookError($c));
    }
}
