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
        // Listing a Page's lead forms, so forms submitted while EzBizi was not
        // listening are read back (MetaLeadForms::catchUp); Meta refuses
        // leadgen_forms without it.
        'pages_manage_ads',
        // Reading, replying to and hiding comments on Page posts
        // (FacebookPageComments).
        'pages_read_user_content',
        'pages_manage_engagement',
    ];

    /**
     * What a person chooses to connect, and the scopes each needs (design 183,
     * Jose 2026-11-05: "it asks me what connections from facebook i want to
     * make, before proceeding"). Every product reads Pages, because Messenger,
     * Instagram comments and lead forms all reach Meta through a Page.
     *
     * @var array<string, array{label: string, description: string, provider: string, scopes: string[]}>
     */
    public const PRODUCTS = [
        'pages' => [
            'label' => 'Pages and posting',
            'description' => 'Post to your Pages and read how posts do.',
            'provider' => 'facebook_page',
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_metadata', 'business_management'],
        ],
        'messenger' => [
            'label' => 'Messenger',
            'description' => 'Messages to your Pages arrive in Leads, and you reply from EzBizi.',
            'provider' => 'facebook_page',
            'scopes' => ['pages_show_list', 'pages_manage_metadata', 'business_management', 'pages_messaging'],
        ],
        'instagram' => [
            'label' => 'Instagram comments',
            'description' => 'Comments on the Instagram account linked to your Page.',
            'provider' => 'facebook_page',
            'scopes' => ['pages_show_list', 'business_management', 'instagram_basic', 'instagram_manage_comments'],
        ],
        'comments' => [
            'label' => 'Facebook comments',
            'description' => 'Comments on your Page posts in Marketing › Comments, with reply and hide.',
            'provider' => 'facebook_page',
            'scopes' => ['pages_show_list', 'business_management', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement'],
        ],
        'ads' => [
            'label' => 'Ad accounts',
            'description' => 'Spend and results in Reports, pausing and re-budgeting campaigns, and lead-ad forms into Leads.',
            'provider' => 'facebook_ads',
            'scopes' => ['pages_show_list', 'pages_manage_metadata', 'business_management', 'ads_read', 'ads_management', 'leads_retrieval', 'pages_manage_ads'],
        ],
    ];

    /** The scope that says a product was granted, on a row's recorded scopes. */
    public const MARKS = [
        'pages' => 'pages_manage_posts',
        'messenger' => 'pages_messaging',
        'instagram' => 'instagram_basic',
        'comments' => 'pages_manage_engagement',
        'ads' => 'ads_read',
    ];

    private const TIMEOUT = 10;

    /** @var string[] the products chosen for this sign-in; none is all of them */
    private array $products = [];

    /** This sign-in asks only for these products' scopes. */
    public function withProducts(array $keys): self
    {
        $chosen = clone $this;
        $chosen->products = array_values(array_intersect(array_keys(self::PRODUCTS), $keys));

        return $chosen;
    }

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
        return $this->products === []
            ? self::SCOPES
            : array_values(array_unique(array_merge(...array_map(fn (string $k) => self::PRODUCTS[$k]['scopes'], $this->products))));
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
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
            // Adding a product to an earlier grant: Meta asks again for what was declined.
        ] + ($this->products === [] ? [] : ['auth_type' => 'rerequest']));
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
        // And what was granted: a person can untick any product on Meta's
        // screen, and Integrations says which ones came through.
        $me = $this->get('https://graph.facebook.com/'.self::GRAPH_VERSION.'/me', [
            'fields' => 'id,permissions',
            'access_token' => $access,
        ], 'Facebook');

        $metaUser = isset($me['id']) && is_scalar($me['id']) ? (string) $me['id'] : null;

        return new SocialOAuthGrant(
            accessToken: $access,
            scopes: array_values(array_map(
                fn (array $p) => (string) $p['permission'],
                array_filter((array) ($me['permissions']['data'] ?? []), fn ($p) => is_array($p) && ($p['status'] ?? null) === 'granted' && isset($p['permission'])),
            )),
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
