<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Page posting via Facebook Login for Business.
 *
 * One consent lists the Pages the person can post as. Ads and Messenger are
 * not requested. A long-lived user token is exchanged so Page tokens last.
 */
final class FacebookOAuth implements SocialOAuth
{
    public const GRAPH_VERSION = 'v21.0';

    public const CALLBACK_PATH = '/api/v1/social/facebook/callback';

    /** @var string[] */
    public const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        // Messenger enquiries into Leads, and replying to them
        // (MetaMessages, SubscribeMetaMessages).
        'pages_messaging',
        'pages_manage_metadata',
        // Pages a person manages through a Business portfolio rather than
        // directly (identify()), and the comments on the Instagram account
        // linked to a Page (InstagramComments).
        'business_management',
        'instagram_basic',
        'instagram_manage_comments',
        // Lead-ad form answers into Leads as "Meta Form" (MetaLeadForms).
        'leads_retrieval',
        // Ad spend and results for Reports, from the same connect - the
        // ad accounts become Facebook Ads connections (SocialAuthController).
        'ads_read',
        // Pausing, resuming and re-budgeting those campaigns (MetaAdsManager).
        'ads_management',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::FacebookPage;
    }

    public function routeKey(): string
    {
        return 'facebook';
    }

    public function scopes(): array
    {
        return self::SCOPES;
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function requiresRefreshToken(): bool
    {
        return false;
    }

    public function notReadyBecause(): ?string
    {
        return MetaApp::notReadyBecause();
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.meta.facebook_redirect'),
            self::CALLBACK_PATH,
            'Facebook',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.facebook.com/'.self::GRAPH_VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => MetaApp::id(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $short = $this->get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token', [
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ], 'Facebook');

        $shortToken = $short['access_token'] ?? null;

        if (! is_string($shortToken) || $shortToken === '') {
            throw SocialOAuthRefused::exchangeFailed('Facebook');
        }

        $long = $this->get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'fb_exchange_token' => $shortToken,
        ], 'Facebook');

        $access = is_string($long['access_token'] ?? null) ? $long['access_token'] : $shortToken;

        // Who authorised this, as Facebook scopes it to this app.
        //
        // Unlike Instagram and Threads, the Facebook token response does not
        // carry it, so it costs one more call. Worth it: Meta's deauthorize
        // and data-deletion callbacks arrive carrying nothing else, and
        // without it there is no way to tell which connections belong to the
        // person who removed the app.
        $me = $this->get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/me', [
            'fields' => 'id',
            'access_token' => $access,
        ], 'Facebook');

        $metaUser = isset($me['id']) && is_scalar($me['id']) ? (string) $me['id'] : null;

        return new SocialOAuthGrant(
            accessToken: $access,
            expiresIn: is_numeric($long['expires_in'] ?? null) ? (int) $long['expires_in'] : null,
            extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        $body = $this->get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/me/accounts', [
            'fields' => 'id,name,access_token',
            'access_token' => $grant->accessToken,
        ], 'Facebook');

        $pages = is_array($body['data'] ?? null) ? $body['data'] : [];

        /*
         * Pages owned by a Business portfolio the person works in. A Page
         * managed only through Business Manager - how franchise groups and
         * agencies hold them - is not in /me/accounts, so without this the
         * owner is told they have no Pages. business_management is what
         * lets EzBizi read the portfolio's Pages; nothing else is read.
         */
        // Optional: somebody may untick business_management on Meta's screen,
        // and that must cost them only the portfolio Pages, not the connect.
        $portfolio = fn (string $path, string $fields) => (array) (Http::timeout(self::TIMEOUT)
            ->get('https://graph.facebook.com/'.self::GRAPH_VERSION.$path, [
                'fields' => $fields,
                'access_token' => $grant->accessToken,
            ])->json('data') ?? []);

        try {
            foreach ($portfolio('/me/businesses', 'id') as $business) {
                if (is_array($business) && isset($business['id'])) {
                    $pages = [...$pages, ...$portfolio('/'.$business['id'].'/owned_pages', 'id,name,access_token')];
                }
            }
        } catch (ConnectionException) {
            // The directly managed Pages above still connect.
        }

        if ($pages === []) {
            throw SocialOAuthRefused::unidentified(
                'Facebook',
                'That Facebook login has no Pages EzBizi can post as, so nothing has been '
                .'connected. Facebook does not let apps publish to a personal profile. Create '
                .'or claim a Page, then connect again.',
            );
        }

        $accounts = [];

        foreach ($pages as $page) {
            // Once each: a Page can be both directly managed and portfolio-owned.
            if (! is_array($page) || isset($accounts[(string) ($page['id'] ?? '')])) {
                continue;
            }

            $id = isset($page['id']) ? trim((string) $page['id']) : '';
            $name = isset($page['name']) ? trim((string) $page['name']) : '';
            $token = isset($page['access_token']) && is_string($page['access_token'])
                ? $page['access_token']
                : null;

            if ($id === '' || $name === '') {
                continue;
            }

            $accounts[$id] = new SocialAccount($id, $name, null, $token);
        }

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified('Facebook');
        }

        return array_values($accounts);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query, string $network): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get($url, $query);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
