<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\InstagramOAuth;
use App\Support\Social\LinkedInOAuth;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThreadsOAuth;
use App\Support\Social\ThumbtackOAuth;
use App\Support\Social\TikTokOAuth;
use App\Support\Social\XOAuth;
use App\Support\Social\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connect groundwork for every OAuth network besides Pinterest (covered in
 * PinterestOAuthTest). Each start URL hits the real authorize host, the
 * redirect URI is config, and a faked callback stores a connection.
 */
class SocialNetworkOAuthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Connect now calls the provider to check it (ConnectSetup): nothing unfaked may leave.
        Http::preventStrayRequests();

        config([
            'services.meta.client_id' => 'a-meta-app-id',
            'services.meta.client_secret' => 'a-meta-app-secret',
            'services.meta.facebook_redirect' => 'https://app.ezbizi.test'.FacebookOAuth::CALLBACK_PATH,
            'services.meta.instagram_redirect' => 'https://app.ezbizi.test'.InstagramOAuth::CALLBACK_PATH,
            'services.meta.threads_redirect' => 'https://app.ezbizi.test'.ThreadsOAuth::CALLBACK_PATH,
            'services.meta.instagram_id' => 'an-instagram-app-id',
            'services.meta.instagram_secret' => 'an-instagram-app-secret',
            'services.meta.threads_id' => 'a-threads-app-id',
            'services.meta.threads_secret' => 'a-threads-app-secret',
            'services.linkedin.client_id' => 'a-linkedin-id',
            'services.linkedin.client_secret' => 'a-linkedin-secret',
            'services.linkedin.redirect' => 'https://app.ezbizi.test'.LinkedInOAuth::CALLBACK_PATH,
            'services.tiktok.client_key' => 'a-tiktok-key',
            'services.tiktok.client_secret' => 'a-tiktok-secret',
            'services.tiktok.redirect' => 'https://app.ezbizi.test'.TikTokOAuth::CALLBACK_PATH,
            'services.youtube.client_id' => 'a-youtube-id.apps.googleusercontent.com',
            'services.youtube.client_secret' => 'a-youtube-secret',
            'services.youtube.redirect' => 'https://app.ezbizi.test'.YouTubeOAuth::CALLBACK_PATH,
            'services.x.client_id' => 'an-x-client-id',
            'services.x.client_secret' => 'an-x-client-secret',
            'services.x.redirect' => 'https://app.ezbizi.test'.XOAuth::CALLBACK_PATH,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function networks(): array
    {
        return [
            'facebook' => ['facebook', 'facebook.com'],
            'instagram' => ['instagram', 'instagram.com'],
            'threads' => ['threads', 'threads.net'],
            'linkedin' => ['linkedin', 'linkedin.com'],
            'tiktok' => ['tiktok', 'tiktok.com'],
            'youtube' => ['youtube', 'accounts.google.com'],
            'x' => ['x', 'x.com/i/oauth2/authorize'],
        ];
    }

    #[DataProvider('networks')]
    public function test_start_sends_people_to_the_networks_own_authorize_host(string $network, string $host): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/social/{$network}/start")
            ->assertOk()
            ->json('data.authorization_url');

        $this->assertStringContainsString($host, $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringNotContainsString('ads:', $url);
        // Facebook asks for messaging since Messenger enquiries became Leads
        // (2026-09-28); when Facebook was posting-only this asserted the
        // opposite. No other network may ask for a Page permission.
        if ($network === 'facebook') {
            $this->assertStringContainsString('pages_messaging', $url);
        } else {
            $this->assertStringNotContainsString('pages_messaging', $url);
        }
    }

    public function test_a_facebook_sign_in_connects_each_page(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'];

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth/access_token')) {
                return Http::response(['access_token' => 'a-long-lived-user-token', 'expires_in' => 5184000]);
            }

            // Asked before /me/accounts, so the more specific match goes first.
            // This is the app-scoped id Meta's deauthorize and data-deletion
            // callbacks arrive carrying.
            if (str_contains($url, '/me?') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/me')) {
                return Http::response(['id' => 'meta-user-abc']);
            }

            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [
                    ['id' => '111', 'name' => 'A-1 Chimney', 'access_token' => 'page-token-111'],
                    ['id' => '222', 'name' => 'A-1 Blog', 'access_token' => 'page-token-222'],
                ]]);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $this->get('/api/v1/social/facebook/callback?code=an-auth-code&state='.$state)
            ->assertOk()
            ->assertSee('Connected', false)
            ->assertDontSee('page-token-111');

        $names = $this->inTenant($t['org'], fn () => SocialConnection::query()->orderBy('display_name')->pluck('display_name')->all());

        $this->assertSame(['A-1 Blog', 'A-1 Chimney'], $names);

        // Each Page subscribes the app, with its own token, or Meta never
        // delivers its Messenger enquiries (found 2026-09-28).
        foreach (['111', '222'] as $page) {
            Http::assertSent(fn ($request) => str_contains($request->url(), "/{$page}/subscribed_apps")
                && $request['access_token'] === "page-token-{$page}"
                && str_contains($request['subscribed_fields'], 'messages'));
        }
        $this->assertSame(
            'page-token-111',
            $this->inTenant($t['org'], fn () => SocialConnection::where('remote_id', '111')->first()->getAttribute('credentials')['access_token']),
        );
    }

    public function test_one_facebook_connect_also_brings_the_ad_accounts(): void
    {
        // Jose, 2026-10-14: "if i am a random business ... i connect my
        // facebook account i should see my financials".
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');
        $this->assertStringContainsString('ads_read', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => 'a-user-token', 'expires_in' => 5184000]),
                str_contains($url, '/me/adaccounts') => Http::response(['data' => [['id' => 'act_777', 'name' => 'A-1 Chimney Ads', 'currency' => 'USD']]]),
                str_contains($url, '/me/accounts') => Http::response(['data' => [['id' => '111', 'name' => 'A-1 Chimney', 'access_token' => 'page-token-111']]]),
                str_contains($url, '/me?') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/me') => Http::response(['id' => 'meta-user-abc']),
                str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
                default => Http::response(['error' => 'unexpected'], 500),
            };
        });

        $this->get('/api/v1/social/facebook/callback?code=an-auth-code&state='.$query['state'])->assertOk();

        $rows = $this->inTenant($t['org'], fn () => SocialConnection::query()->orderBy('provider')->get(['provider', 'remote_id'])
            ->map(fn ($c) => $c->provider.':'.$c->remote_id)->all());

        $this->assertSame(['facebook_ads:act_777', 'facebook_page:111'], $rows);
    }

    public function test_an_ad_account_shared_with_the_business_connects_too(): void
    {
        // A-1 Chimney, 2026-11-05: the account was shared with the EzBizi
        // business as a partner, so /me/adaccounts never listed it.
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => 'a-user-token']),
                str_contains($url, '/me/adaccounts') => Http::response(['data' => []]),
                str_contains($url, '/me/businesses') => Http::response(['data' => [['id' => 'biz-1']]]),
                str_contains($url, '/biz-1/client_ad_accounts') => Http::response(['data' => [['id' => 'act_932', 'name' => 'A1 Chimney', 'currency' => 'USD']]]),
                str_contains($url, '/biz-1/owned_ad_accounts') => Http::response(['data' => [['id' => 'act_932', 'name' => 'A1 Chimney']]]),
                str_contains($url, '/me/accounts') => Http::response(['data' => [['id' => '111', 'name' => 'A-1 Chimney', 'access_token' => 'page-token-111']]]),
                str_contains($url, '/me?') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/me') => Http::response(['id' => 'meta-user-abc']),
                str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
                default => Http::response(['data' => []]),
            };
        });

        $this->get('/api/v1/social/facebook/callback?code=an-auth-code&state='.$query['state'])->assertOk();

        $rows = $this->inTenant($t['org'], fn () => SocialConnection::query()->orderBy('provider')->get(['provider', 'remote_id'])
            ->map(fn ($c) => $c->provider.':'.$c->remote_id)->all());

        // Owned and shared both list it: connected once.
        $this->assertSame(['facebook_ads:act_932', 'facebook_page:111'], $rows);
    }

    public function test_pages_held_through_a_business_portfolio_are_offered_once_each(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => 'user-token']),
                str_contains($url, '/me/accounts') => Http::response(['data' => [
                    ['id' => '111', 'name' => 'Direct Page', 'access_token' => 'page-token-111'],
                ]]),
                str_contains($url, '/me/businesses') => Http::response(['data' => [['id' => 'biz-1']]]),
                // 111 again, as the portfolio also owns it: connected once.
                str_contains($url, '/biz-1/owned_pages') => Http::response(['data' => [
                    ['id' => '111', 'name' => 'Direct Page', 'access_token' => 'page-token-111'],
                    ['id' => '333', 'name' => 'Portfolio Page', 'access_token' => 'page-token-333'],
                ]]),
                str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
                str_contains($url, '/me') => Http::response(['id' => 'meta-user-abc']),
                default => Http::response(['error' => 'unexpected '.$url], 500),
            };
        });

        $this->get('/api/v1/social/facebook/callback?code=an-auth-code&state='.$query['state'])->assertOk();

        $this->assertSame(
            ['Direct Page', 'Portfolio Page'],
            $this->inTenant($t['org'], fn () => SocialConnection::query()->orderBy('display_name')->pluck('display_name')->all()),
        );
    }

    public function test_an_instagram_account_is_stored_under_its_professional_account_id(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/instagram/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth/access_token') => Http::response(['access_token' => 'short-ig', 'user_id' => 17841400000000001]),
                str_contains($url, '/access_token') => Http::response(['access_token' => 'long-ig', 'expires_in' => 5184000]),
                str_contains($url, '/subscribed_apps') => Http::response(['success' => true]),
                // Instagram Login returns both: `id` is app-scoped, `user_id`
                // is the account the webhooks and /messages speak of.
                str_contains($url, '/me') => Http::response(['id' => 'app-scoped-999', 'user_id' => '17841400000000001', 'username' => 'a1chimney']),
                default => Http::response(['error' => 'unexpected '.$url], 500),
            };
        });

        $this->get('/api/v1/social/instagram/callback?code=an-auth-code&state='.$query['state'])->assertOk();

        $this->assertSame(
            ['17841400000000001'],
            $this->inTenant($t['org'], fn () => SocialConnection::query()->pluck('remote_id')->all()),
        );
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.instagram.com/v21.0/me/subscribed_apps'
            && $request['subscribed_fields'] === 'messages');
    }

    public function test_youtube_asks_for_pkce_and_offline_access(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/youtube/start')
            ->assertOk()->json('data.authorization_url');

        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('youtube.upload', $url);
    }

    public function test_x_asks_for_pkce_and_offline_access(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/x/start')
            ->assertOk()->json('data.authorization_url');

        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('tweet.write', $url);
        $this->assertStringContainsString('offline.access', $url);
    }

    /**
     * The callback page draws EzBizi wired to the network being connected, and
     * the network's mark is the very SVG the Flutter catalogue uses. That is
     * one filesystem read away from silently rendering nothing, so it is worth
     * a test: a missing file returns null and the graphic just disappears.
     */
    public function test_the_callback_page_shows_the_network_it_was_connecting(): void
    {
        // No state, so this refuses — the refusal page carries the graphic too.
        $html = $this->get('/api/v1/social/tiktok/callback')
            ->assertStatus(400)
            ->getContent();

        $this->assertStringContainsString('class="link"', $html);
        $this->assertStringContainsString('aria-label="EzBizi and TikTok"', $html);

        // The mark itself, not an empty node. Its first path is enough to say
        // the real file was inlined rather than some placeholder.
        $mark = (string) file_get_contents(base_path('client/assets/social/tiktok.svg'));
        preg_match('/<path[^>]*d="([^"]{20,})"/', $mark, $path);
        $this->assertNotEmpty($path, 'the TikTok mark should have a path to match on');
        $this->assertStringContainsString($path[1], $html);

        // An XML prolog is only legal at the top of a document; this one is
        // being inlined halfway down a body.
        $this->assertStringNotContainsString('<?xml', $html);

        // A refusal must not look like a success.
        $this->assertStringContainsString('cold', $html);
        $this->assertStringNotContainsString('#1E9E62', $html);
    }

    /**
     * The redirect URI we gave Thumbtack has to resolve.
     *
     * PINT-4039 asks for a production and a staging redirect URI, and says
     * staging credentials are not issued until one exists. A 404 there is the
     * whole application failing on a detail nobody would think to check, so the
     * route is pinned: 400 means "reached us and refused an unsigned request",
     * which is right. 404 would mean the enum case went missing, because the
     * route constraint is generated from it.
     */
    public function test_the_thumbtack_callback_resolves_rather_than_404s(): void
    {
        $this->get('/api/v1/social/thumbtack/callback')->assertStatus(400);
    }

    /**
     * Thumbtack is a lead source, not a place anybody posts.
     *
     * Classed as a publishing network it would appear in the Marketing
     * catalogue offering to be posted to, which EzBizi cannot do and Thumbtack
     * does not offer. The permission follows the module that uses the
     * connection, so leads.edit rather than marketing.edit.
     */
    public function test_thumbtack_is_a_lead_source_on_the_leads_permission(): void
    {
        $this->assertContains(SocialProvider::Thumbtack, SocialProvider::dataSources());
        $this->assertFalse(SocialProvider::Thumbtack->isPublishingNetwork());
        $this->assertSame('leads.manage', SocialProvider::Thumbtack->connectPermission());
    }

    /**
     * Credentials alone are not enough to connect, and it says so.
     *
     * The addresses carry real defaults since Thumbtack issued credentials on
     * 2026-09-03, so a blank one now means somebody emptied it in .env rather
     * than that Thumbtack has not told us - which is why the wording changed
     * from "we have not been given them" to "one of them is blank here".
     *
     * Still checked, because an empty authorize_url opens Connect on a window
     * to nowhere, and "nothing happened" is the least diagnosable failure
     * there is. The two refusals name different missing things because
     * different people fix them.
     */
    public function test_thumbtack_says_which_piece_is_missing(): void
    {
        $driver = app(ThumbtackOAuth::class);

        config()->set('services.thumbtack.client_id', null);
        config()->set('services.thumbtack.client_secret', null);
        $this->assertStringContainsString('no Thumbtack app set up', (string) $driver->notReadyBecause());

        config()->set('services.thumbtack.client_id', 'an-id');
        config()->set('services.thumbtack.client_secret', 'a-secret');
        config()->set('services.thumbtack.authorize_url', '');
        $this->assertStringContainsString('API addresses is blank', (string) $driver->notReadyBecause());

        config()->set('services.thumbtack.authorize_url', 'https://example.test/oauth');
        config()->set('services.thumbtack.token_url', 'https://example.test/token');
        config()->set('services.thumbtack.api_base', 'https://example.test/api');
        $this->assertNull($driver->notReadyBecause());
    }

    public function test_host_header_cannot_move_a_facebook_redirect(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $expected = 'https://app.ezbizi.test'.FacebookOAuth::CALLBACK_PATH;

        $spoofed = $this->signedInAs($t['admin'])
            ->withHeaders(['Host' => 'evil.example'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($spoofed, PHP_URL_QUERY), $query);
        $this->assertSame($expected, $query['redirect_uri']);
    }
}
