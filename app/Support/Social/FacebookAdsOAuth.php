<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A business's Facebook ad accounts, read for spend and results (Reports).
 *
 * Its own connect, not more scopes on FacebookOAuth: ads_read is not approved
 * for other businesses (Standard access - only people with a role on the app),
 * and asking for it on the Page connect would put that risk on every Page
 * connect (Jose, 2026-10-12). Each ad account the person can read becomes one
 * connection; the ones nobody wants are disconnected like a Page.
 */
final class FacebookAdsOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/facebook-ads/callback';

    /** @var string[] */
    public const SCOPES = ['ads_read', 'ads_management', 'business_management'];

    private const TIMEOUT = 15;

    public function provider(): SocialProvider
    {
        return SocialProvider::FacebookAds;
    }

    public function routeKey(): string
    {
        return 'facebook-ads';
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
            (string) config('services.meta.facebook_ads_redirect'),
            self::CALLBACK_PATH,
            'Facebook Ads',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => MetaApp::id(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $graph = 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION;

        $short = $this->get($graph.'/oauth/access_token', [
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        $shortToken = $short['access_token'] ?? null;

        if (! is_string($shortToken) || $shortToken === '') {
            throw SocialOAuthRefused::exchangeFailed('Facebook Ads');
        }

        // Long-lived (about 60 days): the daily sync reads with this user token.
        $long = $this->get($graph.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'fb_exchange_token' => $shortToken,
        ]);

        $access = is_string($long['access_token'] ?? null) ? $long['access_token'] : $shortToken;
        $me = $this->get($graph.'/me', ['fields' => 'id', 'access_token' => $access]);
        $metaUser = isset($me['id']) && is_scalar($me['id']) ? (string) $me['id'] : null;

        return new SocialOAuthGrant(
            accessToken: $access,
            expiresIn: is_numeric($long['expires_in'] ?? null) ? (int) $long['expires_in'] : null,
            extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        $accounts = [];
        $read = function (string $edge, string $fields = 'id,name,currency') use ($grant): array {
            $url = 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/'.$edge;
            $query = ['fields' => $fields, 'limit' => 100, 'access_token' => $grant->accessToken];
            $rows = [];

            // ponytail: 20 pages of 100 is far past any A-1 portfolio.
            for ($page = 0; $page < 20 && $url !== null; $page++) {
                $body = $this->get($url, $query);
                $query = [];
                $rows = [...$rows, ...array_filter((array) ($body['data'] ?? []), 'is_array')];
                $url = is_string($body['paging']['next'] ?? null) ? $body['paging']['next'] : null;
            }

            return $rows;
        };

        $rows = $read('me/adaccounts');

        /*
         * An ad account shared with a Business portfolio rather than with the
         * person: a partner share lands on the business (client_ad_accounts),
         * and /me/adaccounts never lists it. A-1 Chimney's account reached
         * EzBizi exactly that way on 2026-11-05 and the connect said "cannot
         * read any ad accounts". business_management is what reads these.
         * Optional, like the portfolio Pages: an unticked business costs only
         * its own accounts.
         */
        try {
            foreach ($read('me/businesses', 'id') as $business) {
                foreach (['owned_ad_accounts', 'client_ad_accounts'] as $edge) {
                    $rows = [...$rows, ...$read($business['id'].'/'.$edge)];
                }
            }
        } catch (SocialOAuthRefused) {
            // The person's own accounts above still connect.
        }

        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));

            if ($id !== '' && ! isset($accounts[$id])) {
                $name = trim((string) ($row['name'] ?? '')) ?: $id;
                $accounts[$id] = new SocialAccount($id, $name, isset($row['currency']) ? (string) $row['currency'] : null);
            }
        }

        $accounts = array_values($accounts);

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified(
                'Facebook Ads',
                'That Facebook login cannot read any ad accounts, so nothing has been connected. '
                .'Sign in as somebody with access to the business\'s ad account and try again.',
            );
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        try {
            // Meta's next link carries every parameter, cursor and token included;
            // a query array, even an empty one, would replace them all.
            $response = $query === []
                ? Http::timeout(self::TIMEOUT)->get($url)
                : Http::timeout(self::TIMEOUT)->get($url, $query);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Facebook Ads');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('Facebook Ads');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
