<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\OnboardWhatsAppNumber;
use App\Models\SocialConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Support\Social\ConnectionChecks;
use App\Support\Social\ConnectionSetup;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialProvider;
use App\Support\Social\WhatsAppCloud;
use App\Support\Social\WhatsAppOAuth;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/** A tenant connects its OWN WhatsApp number - Embedded Signup (2026-10-19). */
class WhatsAppConnectTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private const PHONE_ID = '555000111';

    private const WABA = '777000222';

    protected function setUp(): void
    {
        parent::setUp();

        // Connect now calls the provider to check it (ConnectSetup): nothing unfaked may leave.
        Http::preventStrayRequests();

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

        // Our page, which opens Meta through FB.login: a dialog/oauth link
        // drops extras, and coexistence never showed (2026-10-29).
        $this->assertStringStartsWith('https://app.ezbizi.test'.WhatsAppOAuth::LAUNCH_PATH.'?state=', $url);

        $page = $this->get(WhatsAppOAuth::LAUNCH_PATH.'?state='.$query['state'])->assertOk()->getContent();
        $this->assertStringContainsString('FB.login(', $page);
        $this->assertStringContainsString('"config_id":"1772377890501449"', $page);
        $this->assertStringContainsString('"featureType":"whatsapp_business_app_onboarding"', $page);
        $this->assertStringContainsString('"sessionInfoVersion":"3"', $page);
        $this->assertStringContainsString('"override_default_response_type":true', $page);
        $this->assertStringContainsString(WhatsAppOAuth::CALLBACK_PATH.'?state='.$query['state'], $page);

        $this->get(WhatsAppOAuth::LAUNCH_PATH.'?state=<script>')->assertNotFound();
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

    public function test_a_number_kept_in_the_business_app_is_synced_and_never_registered(): void
    {
        $t = $this->provisionTenant('Acme');
        $connection = $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
            'provider' => 'whatsapp', 'remote_id' => self::PHONE_ID, 'display_name' => 'Acme',
        ])->forceFill(['credentials' => ['access_token' => 'the-business-token']]));
        $this->inTenant($t['org'], fn () => $connection->save());
        Http::fake(fn (Request $r) => str_contains($r->url(), '/'.self::PHONE_ID.'?')
            ? Http::response(['display_phone_number' => '+1 305-555-0100', 'is_on_biz_app' => true])
            : Http::response(['success' => true]));

        // Run as the queue runs it: no tenant in context (prod, 2026-10-29).
        app(TenantContext::class)->forget();
        (new OnboardWhatsAppNumber($connection->id, self::WABA))->handle();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/register'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::PHONE_ID.'/smb_app_data') && $r['sync_type'] === 'smb_app_state_sync');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::PHONE_ID.'/smb_app_data') && $r['sync_type'] === 'history');
        $this->assertSame('+13055550100', WhatsAppNumber::query()->withoutGlobalScopes()->sole()->display_number);
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

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$connection->id, $code)
            ->assertOk();

        $this->assertSame(0, WhatsAppNumber::query()->withoutGlobalScopes()->count());
    }

    public function test_a_number_another_tenant_routes_is_refused_before_anything_is_saved(): void
    {
        $a = $this->provisionTenant('Owner');
        $this->inTenant($a['org'], fn () => WhatsAppNumber::query()->forceCreate([
            'organization_id' => $a['org']->id, 'phone_number_id' => self::PHONE_ID, 'waba_id' => 'theirs',
        ]));
        $b = $this->provisionTenant('Intruder');

        $url = $this->signedInAs($b['admin'])
            ->postJson('/api/v1/social/whatsapp/start')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Queue::fake();
        $this->metaAnswers();
        $this->get('/api/v1/social/whatsapp/callback?code=a-code&state='.$query['state'])
            ->assertSee('This WhatsApp number is already connected to another EzBizi account.', false);

        $this->assertSame(0, SocialConnection::query()->withoutGlobalScopes()->where('provider', 'whatsapp')->count());
        Queue::assertNotPushed(OnboardWhatsAppNumber::class);
        $this->assertSame($a['org']->id, WhatsAppNumber::query()->withoutGlobalScopes()->sole()->organization_id);
    }

    public function test_a_disconnected_tenant_number_never_falls_back_to_the_global_token(): void
    {
        [$t, $connection] = $this->connected();

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/connections/'.$connection->id, $code)->assertOk();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]]));

        try {
            $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)->send('hello', '(305) 724-2199', from: '+13055550100'));
            $this->fail('A send on a disconnected number went out.');
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();

        // And if the number row were somehow still there, tokenFor refuses.
        $this->inTenant($t['org'], fn () => WhatsAppNumber::query()->forceCreate([
            'organization_id' => $t['org']->id, 'phone_number_id' => self::PHONE_ID,
            'waba_id' => self::WABA, 'social_connection_id' => $connection->id,
        ]));
        $this->expectException(\RuntimeException::class);
        WhatsAppCloud::tokenFor(self::PHONE_ID);
    }

    /** @return array{0: array, 1: SocialConnection} A tenant's connected number, seeded rather than signed up (one Http::fake per test). */
    private function seeded(): array
    {
        $t = $this->provisionTenant('Acme');

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $c = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
                'provider' => 'whatsapp', 'remote_id' => self::PHONE_ID, 'display_name' => 'Acme',
            ]);
            $c->forceFill(['credentials' => ['access_token' => 'the-business-token', 'pin' => '123456']])->save();
            WhatsAppNumber::query()->forceCreate([
                'organization_id' => $t['org']->id, 'phone_number_id' => self::PHONE_ID, 'waba_id' => self::WABA,
                'display_name' => 'Acme Plumbing', 'social_connection_id' => $c->id, 'purpose' => 'company',
            ]);

            return $c;
        });

        return [$t, $connection];
    }

    public function test_a_new_number_is_customer_chat_until_its_card_says_otherwise(): void
    {
        [$t, $connection] = $this->connected();
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->sole();
        $this->assertSame('company', $number->purpose);

        $this->inTenant($t['org'], fn () => $number->forceFill(['purpose' => 'support'])->save());
        (new OnboardWhatsAppNumber($connection->id, self::WABA))->handle();
        $this->assertSame('support', $number->fresh()->purpose, 'A reconnect overwrote the chosen purpose.');

        $card = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections')->assertOk()->json('data'))
            ->firstWhere('id', $connection->id);
        $this->assertSame(['purpose' => 'support'], $card['whatsapp']);
    }

    public function test_choosing_reports_moves_it_off_the_number_that_had_it(): void
    {
        [$t, $connection] = $this->seeded();
        $old = $this->inTenant($t['org'], fn () => WhatsAppNumber::query()->forceCreate([
            'organization_id' => $t['org']->id, 'phone_number_id' => '888000333', 'waba_id' => 'ours', 'purpose' => 'reports',
        ]));
        $url = '/api/v1/social/connections/'.$connection->id.'/whatsapp-purpose';

        $this->signedInAs($t['admin'])->patchJson($url, ['purpose' => 'reports'])->assertOk()->assertJsonPath('data.purpose', 'reports');

        $this->assertSame('reports', WhatsAppNumber::query()->withoutGlobalScopes()->where('social_connection_id', $connection->id)->value('purpose'));
        $this->assertSame('company', $old->fresh()->purpose);

        $this->signedInAs($t['admin'])->patchJson($url, ['purpose' => 'everything'])->assertUnprocessable();
        $other = $this->provisionTenant('Other');
        $this->signedInAs($other['admin'])->patchJson($url, ['purpose' => 'support'])->assertNotFound();
    }

    public function test_the_health_check_fixes_what_it_can_and_says_what_meta_blocks(): void
    {
        [$t, $connection] = $this->seeded();
        $registered = $subscribed = false;

        Http::fake(function (Request $r) use (&$registered, &$subscribed) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/debug_token') => Http::response(['data' => [
                    'type' => 'SYSTEM_USER', 'is_valid' => true, 'expires_at' => 0,
                    'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging', 'public_profile'],
                ]]),
                str_contains($url, '/subscribed_apps') => $r->method() === 'POST'
                    ? Http::response(['success' => $subscribed = true])
                    : Http::response(['data' => $subscribed ? [['whatsapp_business_api_data' => ['id' => 'a-meta-app-id']]] : []]),
                str_contains($url, '/register') => Http::response(['success' => $registered = true]),
                str_contains($url, '/'.self::WABA.'?') => Http::response(['owner_business_info' => ['id' => '42424242', 'name' => 'Acme Inc']]),
                str_contains($url, '/'.self::PHONE_ID.'?') => Http::response([
                    'status' => $registered ? 'CONNECTED' : 'PENDING', 'is_on_biz_app' => false,
                    'verified_name' => 'Acme Plumbing', 'name_status' => 'NON_EXISTS', 'quality_rating' => 'UNKNOWN',
                    'health_status' => ['can_send_message' => 'BLOCKED', 'entities' => [
                        ['entity_type' => 'PHONE_NUMBER', 'can_send_message' => 'LIMITED',
                            'errors' => [['error_code' => 138024, 'error_description' => 'Calling cannot use SIP.']],
                            'additional_info' => ['Your display name has not been approved yet.']],
                        ['entity_type' => 'WABA', 'can_send_message' => 'BLOCKED', 'errors' => [['error_code' => 141006,
                            'error_description' => 'There is an error with the payment method.', 'possible_solution' => 'Add a new payment method.']]],
                    ]],
                ]),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });

        $lines = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections/'.$connection->id.'/whatsapp-health')->assertOk()->json('data'))->keyBy('key');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::PHONE_ID.'/register') && $r['pin'] === '123456');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), self::WABA.'/subscribed_apps') && $r->method() === 'POST');
        $this->assertTrue($lines['registered']['ok']);
        $this->assertStringContainsString('registered it just now', $lines['registered']['detail']);
        $this->assertTrue($lines['webhook']['ok']);
        $this->assertStringContainsString('just now', $lines['webhook']['detail']);
        $this->assertTrue($lines['token']['ok']);
        $this->assertFalse($lines['display_name']['ok']);
        $this->assertFalse($lines['sending']['ok']);
        $this->assertStringContainsString('payment method', $lines['sending']['action']);
        $this->assertStringNotContainsString('SIP', $lines['sending']['action']);
        // The tenant pays Meta itself (Jose, 2026-11-16): the fix opens ITS business's billing for this account.
        $this->assertFalse($lines['billing']['ok']);
        $this->assertSame('There is an error with the payment method.', $lines['billing']['detail']);
        $this->assertSame('https://business.facebook.com/billing_hub/payment_settings/?asset_id='.self::WABA
            .'&business_id=42424242&placement=whatsapp', $lines['billing']['url']);
    }

    public function test_a_send_meta_failed_for_payment_shows_on_the_billing_line_until_one_is_delivered(): void
    {
        [$t, $connection] = $this->seeded();
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), '/debug_token') => Http::response(['data' => []]),
            str_contains($r->url(), '/subscribed_apps') => Http::response(['data' => []]),
            str_contains($r->url(), '/'.self::WABA.'?') => Http::response(['owner_business_info' => ['id' => '42424242', 'name' => 'Acme Inc']]),
            default => Http::response(['status' => 'CONNECTED', 'health_status' => ['can_send_message' => 'AVAILABLE', 'entities' => []]]),
        });
        $send = fn (string $status, ?string $reason, string $at) => $this->inTenant($t['org'], fn () => WhatsAppMessage::query()->forceCreate([
            'organization_id' => $t['org']->id, 'phone_number_id' => self::PHONE_ID, 'direction' => 'out',
            'counterparty' => '13055550100', 'status' => $status, 'status_reason' => $reason, 'kind' => 'template', 'created_at' => $at,
        ]));
        $billing = fn () => collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections/'.$connection->id.'/whatsapp-health')->assertOk()->json('data'))->firstWhere('key', 'billing');

        $send('failed', 'Business eligibility payment issue', '2026-11-16 10:00:00');
        $line = $billing();
        $this->assertFalse($line['ok']);
        $this->assertStringContainsString('Business eligibility payment issue', $line['detail']);
        $this->assertSame('Add a payment method in your Meta Business Manager › Billing.', $line['action']);
        // What Integrations › WhatsApp draws: a failing check whose button opens the tenant's own billing.
        $this->inTenant($t['org'], fn () => ConnectionSetup::readBack($connection->fresh()));
        $check = ConnectionChecks::of($connection->fresh())['billing'];
        $this->assertSame('fail', $check['state']);
        $this->assertSame('https://business.facebook.com/billing_hub/payment_settings/?asset_id='.self::WABA
            .'&business_id=42424242&placement=whatsapp', $check['fix']['url']);

        $send('delivered', null, '2026-11-16 11:00:00');
        $line = $billing();
        $this->assertTrue($line['ok']);
        $this->assertStringContainsString('Acme Inc', $line['detail']);
    }

    public function test_it_is_a_sending_account_gated_on_communication(): void
    {
        $this->assertFalse(SocialProvider::WhatsApp->isPublishingNetwork());
        $this->assertSame('phone.edit', SocialProvider::WhatsApp->connectPermission());
        $this->assertSame('/social/whatsapp/start', SocialProvider::WhatsApp->startPath());
    }
}
