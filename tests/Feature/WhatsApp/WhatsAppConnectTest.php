<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\OnboardWhatsAppNumber;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialProvider;
use App\Support\Social\WhatsAppCloud;
use App\Support\Social\WhatsAppOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A tenant connects its OWN WhatsApp number - Embedded Signup (2026-10-19). */
class WhatsAppConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PHONE_ID = '555000111';

    private const WABA = '777000222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.client_id' => 'a-meta-app-id',
            'services.meta.client_secret' => 'a-meta-app-secret',
            'services.meta.whatsapp_redirect' => 'https://app.ezbizi.test'.WhatsAppOAuth::CALLBACK_PATH,
            'services.meta.whatsapp_signup_config' => '1772377890501449',
            'services.meta.whatsapp_token' => 'the-global-token',
        ]);
    }

    private function metaAnswers(): void
    {
        $n = 0;

        Http::fake(function (Request $request) use (&$n) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => 'the-business-token']),
                str_contains($url, '/debug_token') => Http::response(['data' => ['granular_scopes' => [
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => [self::WABA]],
                    ['scope' => 'whatsapp_business_management', 'target_ids' => [self::WABA]],
                ]]]),
                str_contains($url, self::WABA.'/phone_numbers') => Http::response(['data' => [[
                    'id' => self::PHONE_ID, 'display_phone_number' => '+1 305-555-0100',
                    'verified_name' => 'Acme Plumbing', 'status' => 'CONNECTED',
                ]]]),
                str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
                str_contains($url, '/register') => Http::response(['success' => true]),
                str_contains($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.'.(++$n)]]]),
                str_contains($url, '/'.self::PHONE_ID) => Http::response([
                    'display_phone_number' => '+1 305-555-0100', 'verified_name' => 'Acme Plumbing',
                ]),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });
    }

    /** @return array{0: array, 1: SocialConnection} */
    private function connected(): array
    {
        $t = $this->provisionTenant('Acme');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/whatsapp/start')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->metaAnswers();
        $this->get('/api/v1/social/whatsapp/callback?code=a-code&state='.$query['state'])
            ->assertOk()->assertSee('Connected', false)->assertDontSee('the-business-token');

        return [$t, SocialConnection::query()->withoutGlobalScopes()->where('provider', 'whatsapp')->firstOrFail()];
    }

    public function test_the_authorize_url_carries_the_signup_configuration_and_no_scope(): void
    {
        $t = $this->provisionTenant('Acme');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/whatsapp/start')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        $this->assertSame('1772377890501449', $query['config_id']);
        $this->assertArrayNotHasKey('scope', $query);
        $this->assertSame('true', $query['override_default_response_type']);
        $this->assertSame('3', json_decode($query['extras'], true)['sessionInfoVersion']);
    }

    public function test_identify_turns_the_shared_wabas_into_one_account_per_number(): void
    {
        $this->metaAnswers();

        $accounts = app(WhatsAppOAuth::class)->identify(new SocialOAuthGrant('the-business-token'));

        $this->assertCount(1, $accounts);
        $this->assertSame(self::PHONE_ID, $accounts[0]->remoteId);
        $this->assertSame('Acme Plumbing (+1 305-555-0100)', $accounts[0]->displayName);
        $this->assertSame(self::WABA, $accounts[0]->wabaId);
        // The app token, never the business's, reads debug_token.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/debug_token')
            && $r['access_token'] === 'a-meta-app-id|a-meta-app-secret');
    }

    public function test_connecting_onboards_the_number_into_its_tenant_and_twice_changes_nothing(): void
    {
        [$t, $connection] = $this->connected();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::WABA.'/subscribed_apps')
            && $r->hasHeader('Authorization', 'Bearer the-business-token'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::PHONE_ID.'/register')
            && $r['messaging_product'] === 'whatsapp' && preg_match('/^\d{6}$/', $r['pin']));

        $number = WhatsAppNumber::query()->withoutGlobalScopes()->sole();
        $this->assertSame($t['org']->id, $number->organization_id);
        $this->assertSame(self::PHONE_ID, $number->phone_number_id);
        $this->assertSame(self::WABA, $number->waba_id);
        $this->assertSame('+13055550100', $number->display_number);
        $this->assertSame($connection->id, $number->social_connection_id);

        $pin = $connection->fresh()->credentials['pin'];
        $this->assertSame(self::WABA, $connection->fresh()->credentials['waba_id']);

        // Again, with Meta saying it is already registered: still one row, same PIN.
        Http::fake(fn (Request $r) => str_contains($r->url(), '/register')
            ? Http::response(['error' => ['message' => 'Phone number is already registered']], 400)
            : Http::response(['success' => true, 'display_phone_number' => '+1 305-555-0100']));
        (new OnboardWhatsAppNumber($connection->id, self::WABA))->handle();

        $this->assertSame(1, WhatsAppNumber::query()->withoutGlobalScopes()->count());
        $this->assertSame($pin, $connection->fresh()->credentials['pin']);
    }

    public function test_a_connected_number_sends_with_its_own_token_and_others_with_the_global_one(): void
    {
        [$t] = $this->connected();

        $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)->send('hello', '(305) 724-2199'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::PHONE_ID.'/messages')
            && $r->hasHeader('Authorization', 'Bearer the-business-token'));

        $this->assertSame('the-global-token', WhatsAppCloud::tokenFor('954-is-ezbizis-own'));
        $this->assertSame('the-business-token', WhatsAppCloud::tokenFor(self::PHONE_ID));
    }

    public function test_disconnecting_removes_the_number_so_nothing_sends_on_it(): void
    {
        [$t, $connection] = $this->connected();

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$connection->id)
            ->assertOk();

        $this->assertSame(0, WhatsAppNumber::query()->withoutGlobalScopes()->count());
    }

    public function test_it_is_a_sending_account_gated_on_communication(): void
    {
        $this->assertFalse(SocialProvider::WhatsApp->isPublishingNetwork());
        $this->assertSame('phone.edit', SocialProvider::WhatsApp->connectPermission());
        $this->assertSame('/social/whatsapp/start', SocialProvider::WhatsApp->startPath());
    }
}
