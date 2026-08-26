<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\InstagramOAuth;
use App\Support\Social\LinkedInOAuth;
use App\Support\Social\TikTokOAuth;
use App\Support\Social\ThreadsOAuth;
use App\Support\Social\XOAuth;
use App\Support\Social\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        config([
            'services.meta.client_id'            => 'a-meta-app-id',
            'services.meta.client_secret'        => 'a-meta-app-secret',
            'services.meta.facebook_redirect'    => 'https://app.ezbizi.test'.FacebookOAuth::CALLBACK_PATH,
            'services.meta.instagram_redirect'   => 'https://app.ezbizi.test'.InstagramOAuth::CALLBACK_PATH,
            'services.meta.threads_redirect'     => 'https://app.ezbizi.test'.ThreadsOAuth::CALLBACK_PATH,
            'services.meta.instagram_id'         => 'an-instagram-app-id',
            'services.meta.instagram_secret'     => 'an-instagram-app-secret',
            'services.meta.threads_id'           => 'a-threads-app-id',
            'services.meta.threads_secret'       => 'a-threads-app-secret',
            'services.linkedin.client_id'        => 'a-linkedin-id',
            'services.linkedin.client_secret'    => 'a-linkedin-secret',
            'services.linkedin.redirect'         => 'https://app.ezbizi.test'.LinkedInOAuth::CALLBACK_PATH,
            'services.tiktok.client_key'         => 'a-tiktok-key',
            'services.tiktok.client_secret'      => 'a-tiktok-secret',
            'services.tiktok.redirect'           => 'https://app.ezbizi.test'.TikTokOAuth::CALLBACK_PATH,
            'services.youtube.client_id'         => 'a-youtube-id.apps.googleusercontent.com',
            'services.youtube.client_secret'     => 'a-youtube-secret',
            'services.youtube.redirect'          => 'https://app.ezbizi.test'.YouTubeOAuth::CALLBACK_PATH,
            'services.x.client_id'               => 'an-x-client-id',
            'services.x.client_secret'           => 'an-x-client-secret',
            'services.x.redirect'                => 'https://app.ezbizi.test'.XOAuth::CALLBACK_PATH,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function networks(): array
    {
        return [
            'facebook'  => ['facebook', 'facebook.com'],
            'instagram' => ['instagram', 'instagram.com'],
            'threads'   => ['threads', 'threads.net'],
            'linkedin'  => ['linkedin', 'linkedin.com'],
            'tiktok'    => ['tiktok', 'tiktok.com'],
            'youtube'   => ['youtube', 'accounts.google.com'],
            'x'         => ['x', 'x.com/i/oauth2/authorize'],
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
        $this->assertStringNotContainsString('pages_messaging', $url);
    }

    public function test_a_facebook_sign_in_connects_each_page(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'];

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
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

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $this->get('/api/v1/social/facebook/callback?code=an-auth-code&state='.$state)
            ->assertOk()
            ->assertSee('Connected', false)
            ->assertDontSee('page-token-111');

        $names = $this->inTenant($t['org'], fn () => SocialConnection::query()->orderBy('display_name')->pluck('display_name')->all());

        $this->assertSame(['A-1 Blog', 'A-1 Chimney'], $names);
        $this->assertSame(
            'page-token-111',
            $this->inTenant($t['org'], fn () => SocialConnection::where('remote_id', '111')->first()->getAttribute('credentials')['access_token']),
        );
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
