<?php

namespace App\Support\Social;

use App\Support\Pkce;
use App\Support\Tasks\Monday\MondayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Connect Monday: the "EzBizi" app on Monday's Developer Center (Jose,
 * 2026-11-12), so the Task Board can be kept in step with the boards imported
 * from Monday until Monday is retired (App\Support\Tasks\Monday\MondaySync).
 *
 * Client id, secret and signing secret come from MONDAY_CLIENT_ID,
 * MONDAY_CLIENT_SECRET and MONDAY_SIGNING_SECRET. The redirect URL to register
 * on the app, character for character, is APP_URL + CALLBACK_PATH
 * (https://app.ezbizi.com/api/v1/social/monday/callback).
 *
 * Monday's tokens do not expire and come with no refresh token. The token acts
 * as the Monday user who connected and sees only what that user sees. One
 * connection per Monday account.
 */
final class MondayOAuth implements SocialOAuth
{
    /** What the app must be granted on Monday (OAuth & Permissions). Read only. */
    public const SCOPES = ['me:read', 'users:read', 'boards:read', 'updates:read', 'assets:read', 'webhooks:write'];

    public const CALLBACK_PATH = '/api/v1/social/monday/callback';

    private const AUTHORIZE_ENDPOINT = 'https://auth.monday.com/oauth2/authorize';

    // The New OAuth Flow (on for the EzBizi app) exchanges codes here, as JSON;
    // the legacy /oauth2/token rejects its codes with invalid_grant (2026-11-12).
    private const TOKEN_ENDPOINT = 'https://auth.monday.com/oauth_ms/oauth/token';

    private const TIMEOUT = 15;

    public function provider(): SocialProvider
    {
        return SocialProvider::Monday;
    }

    public function routeKey(): string
    {
        return 'monday';
    }

    public function scopes(): array
    {
        return self::SCOPES;
    }

    /** The app has Monday's "New OAuth flow" on, which requires PKCE (S256). */
    public function usesPkce(): bool
    {
        return true;
    }

    public function requiresRefreshToken(): bool
    {
        return false;
    }

    public function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(config('services.monday.client_id'), config('services.monday.client_secret'))) {
            return null;
        }

        return 'EzBizi has no Monday app set up on this installation yet, so Monday cannot be '
            .'connected. Nothing is wrong with your Monday account — the missing piece is at '
            .'EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig((string) config('services.monday.redirect'), self::CALLBACK_PATH, 'Monday');
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        if ($verifier === null || $verifier === '') {
            throw SocialOAuthRefused::notConfigured('Monday', 'A sign-in was started without PKCE.');
        }

        // The scopes are the app's own (set on Monday); asking for fewer here is not needed.
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => (string) config('services.monday.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $response = Http::asJson()->acceptJson()->timeout(self::TIMEOUT)->post(self::TOKEN_ENDPOINT, [
                'client_id' => (string) config('services.monday.client_id'),
                'client_secret' => (string) config('services.monday.client_secret'),
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => (string) $verifier,
            ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Monday');
        }

        $access = $response->json('access_token');
        if ($response->failed() || ! is_string($access) || $access === '') {
            report(new \RuntimeException('Monday refused the code exchange: '.$response->status().' '.$response->json('error').' '.$response->json('error_description')));
            throw SocialOAuthRefused::exchangeFailed('Monday');
        }

        $refresh = $response->json('refresh_token');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            scopes: SocialOAuthGrant::parseScopes($response->json('scope')),
            expiresIn: is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        );
    }

    /** @return SocialAccount[] the Monday account, named for the person who connected it */
    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $me = (new MondayClient($grant->accessToken))->query('{ me { id name email account { id name slug } } }')['me'] ?? null;
        } catch (\Throwable) {
            throw SocialOAuthRefused::unidentified('Monday');
        }
        $account = $me['account'] ?? null;
        if (! is_array($account) || empty($account['id'])) {
            throw SocialOAuthRefused::unidentified('Monday');
        }

        return [new SocialAccount(
            remoteId: (string) $account['id'],
            displayName: trim((string) ($account['name'] ?: $account['slug'] ?? 'Monday')),
            handle: is_string($me['email'] ?? null) ? $me['email'] : null,
        )];
    }
}
