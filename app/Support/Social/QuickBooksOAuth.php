<?php

namespace App\Support\Social;

use App\Support\Financials\QuickBooks\QuickBooksClient;
use App\Support\Financials\QuickBooks\QuickBooksRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Connect QuickBooks Online (Shoval via Jose's Monday board, 2026-11-20:
 * expenses per market). Intuit OAuth 2.0, read only.
 *
 * QUICKBOOKS_CLIENT_ID / QUICKBOOKS_CLIENT_SECRET from the "EzBizi" app at
 * developer.intuit.com (Development keys with QUICKBOOKS_ENVIRONMENT=sandbox,
 * Production keys with production). The redirect URI to register, character
 * for character: APP_URL + CALLBACK_PATH
 * (https://app.ezbizi.com/api/v1/social/quickbooks/callback).
 *
 * Intuit hands the company (realmId) back on the callback, not in the token
 * response: SocialAuthController passes it through withRealm(). Access tokens
 * last an hour; refresh tokens ROTATE (each refresh may return a new one, and
 * the old one stops working) — QuickBooksToken renews under a lock and stores
 * the new pair in one write.
 */
final class QuickBooksOAuth implements SocialOAuth
{
    public const SCOPES = ['com.intuit.quickbooks.accounting'];

    public const CALLBACK_PATH = '/api/v1/social/quickbooks/callback';

    public const AUTHORIZE_ENDPOINT = 'https://appcenter.intuit.com/connect/oauth2';

    public const TOKEN_ENDPOINT = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const REVOKE_ENDPOINT = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    private const TIMEOUT = 20;

    public function provider(): SocialProvider
    {
        return SocialProvider::QuickBooks;
    }

    public function routeKey(): string
    {
        return 'quickbooks';
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
        return true;
    }

    public function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(config('services.quickbooks.client_id'), config('services.quickbooks.client_secret'))) {
            return null;
        }

        return 'EzBizi has no QuickBooks app set up on this installation yet, so QuickBooks cannot be '
            .'connected. Nothing is wrong with your QuickBooks company — the missing piece is at '
            .'EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig((string) config('services.quickbooks.redirect'), self::CALLBACK_PATH, 'QuickBooks');
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => (string) config('services.quickbooks.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $response = $this->tokenRequest([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('QuickBooks');
        }

        $access = $response->json('access_token');
        if ($response->failed() || ! is_string($access) || $access === '') {
            report(new \RuntimeException('QuickBooks refused the code exchange: '.$response->status().' '.$response->json('error')));
            throw SocialOAuthRefused::exchangeFailed('QuickBooks');
        }

        return $this->grant($response->json());
    }

    /**
     * A new pair for a refresh token. Called only by QuickBooksToken, under its
     * lock. invalid_grant (or 401) is definitive: QuickBooksRefused, never
     * retried. Anything else is a RuntimeException: nothing was spent.
     */
    public function refresh(string $refreshToken): SocialOAuthGrant
    {
        try {
            $response = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        } catch (ConnectionException) {
            throw new \RuntimeException('QuickBooks did not answer the token refresh.');
        }

        if ($response->json('error') === 'invalid_grant' || in_array($response->status(), [401, 403], true)) {
            throw new QuickBooksRefused(QuickBooksRefused::RECONNECT);
        }
        $access = $response->json('access_token');
        if ($response->failed() || ! is_string($access) || $access === '') {
            throw new \RuntimeException('QuickBooks refused the token refresh: '.$response->status().' '.$response->json('error'));
        }

        return $this->grant($response->json());
    }

    /** Best effort at disconnect: Intuit forgets the grant. False when it would not. */
    public function revoke(string $token): bool
    {
        try {
            return Http::withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
                ->acceptJson()->asJson()->timeout(self::TIMEOUT)
                ->post(self::REVOKE_ENDPOINT, ['token' => $token])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** The company this consent chose, as Intuit put it on the callback (?realmId=). */
    public function withRealm(SocialOAuthGrant $grant, string $realmId): SocialOAuthGrant
    {
        return new SocialOAuthGrant($grant->accessToken, $grant->refreshToken, $grant->scopes, $grant->expiresIn,
            ['realm_id' => $realmId] + $grant->extra);
    }

    /** @return SocialAccount[] the one QuickBooks company, named as QuickBooks names it */
    public function identify(SocialOAuthGrant $grant): array
    {
        $realm = $grant->extra['realm_id'] ?? null;
        if (! is_string($realm) || ! preg_match('/^\d{1,32}$/', $realm)) {
            throw SocialOAuthRefused::unidentified('QuickBooks');
        }

        try {
            $info = app(QuickBooksClient::class)->get($grant->accessToken, $realm, 'companyinfo/'.$realm);
        } catch (\Throwable) {
            throw SocialOAuthRefused::unidentified('QuickBooks');
        }
        $company = $info['CompanyInfo'] ?? null;
        if (! is_array($company)) {
            throw SocialOAuthRefused::unidentified('QuickBooks');
        }

        return [new SocialAccount(
            remoteId: $realm,
            displayName: trim((string) ($company['CompanyName'] ?? $company['LegalName'] ?? 'QuickBooks')) ?: 'QuickBooks',
            handle: is_string($company['Email']['Address'] ?? null) ? $company['Email']['Address'] : null,
        )];
    }

    private function tokenRequest(array $form): \Illuminate\Http\Client\Response
    {
        return Http::withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
            ->asForm()->acceptJson()->timeout(self::TIMEOUT)->post(self::TOKEN_ENDPOINT, $form);
    }

    private function grant(array $body): SocialOAuthGrant
    {
        $refresh = $body['refresh_token'] ?? null;

        return new SocialOAuthGrant(
            accessToken: (string) $body['access_token'],
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            scopes: self::SCOPES,
            expiresIn: is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600,
        );
    }
}
