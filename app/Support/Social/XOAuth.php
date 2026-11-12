<?php

namespace App\Support\Social;

use App\Support\Pkce;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * X API v2 OAuth 2.0 with PKCE. Confidential client (Basic auth).
 * tweet.write + offline.access so a post can be scheduled unattended.
 * media.write because a picture is uploaded to X before the post names it;
 * without it the upload is refused and only words can be posted. An account
 * connected before media.write was asked for must reconnect to post pictures.
 */
final class XOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/x/callback';

    /** @var string[] */
    public const SCOPES = [
        'tweet.read',
        'tweet.write',
        'users.read',
        'media.write',
        'offline.access',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::X;
    }

    public function routeKey(): string
    {
        return 'x';
    }

    public function scopes(): array
    {
        return self::SCOPES;
    }

    public function usesPkce(): bool
    {
        return true;
    }

    public function requiresRefreshToken(): bool
    {
        return true;
    }

    public function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(
            config('services.x.client_id'),
            config('services.x.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no X app set up on this installation yet, so an X account cannot '
            .'be connected. Nothing is wrong with the account — the missing piece is at '
            .'EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.x.redirect'),
            self::CALLBACK_PATH,
            'X',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        if ($verifier === null || $verifier === '') {
            throw SocialOAuthRefused::notConfigured('X', 'A sign-in was started without PKCE.');
        }

        return 'https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type'         => 'code',
            'client_id'             => (string) config('services.x.client_id'),
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => implode(' ', self::SCOPES),
            'state'                 => $state,
            'code_challenge'        => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('services.x.client_id'),
                    (string) config('services.x.client_secret'),
                )
                ->timeout(self::TIMEOUT)
                ->post('https://api.x.com/2/oauth2/token', [
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUri(),
                    'code_verifier' => (string) $verifier,
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('X');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('X');
        }

        $access  = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('X');
        }

        if (! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::noLastingPermission('X');
        }

        // expires_in is kept so the publisher renews BEFORE the two hours are
        // up rather than learning it from a refused post.
        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: $refresh,
            scopes: SocialOAuthGrant::parseScopes($response->json('scope')),
            expiresIn: is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        );
    }

    /**
     * A new access token from the stored refresh token.
     *
     * X ROTATES the refresh token: the one sent here is spent the moment X
     * answers, so the caller stores the returned one under a lock
     * (XPublisher::token). A 400 is X saying the refresh token is no good —
     * spent, revoked in the user's settings, or six months unused — and it
     * comes back as HTTP_CONFLICT so the caller can mark the row broken.
     *
     * @throws SocialOAuthRefused
     */
    public function refresh(string $refreshToken): SocialOAuthGrant
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('services.x.client_id'),
                    (string) config('services.x.client_secret'),
                )
                ->timeout(self::TIMEOUT)
                ->post('https://api.x.com/2/oauth2/token', [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('X');
        }

        // X answers a dead refresh token with 400 and `invalid_request`, not
        // only the RFC's `invalid_grant`; both mean the same thing here.
        if ($response->status() === 400
            && in_array($response->json('error'), ['invalid_grant', 'invalid_request'], strict: true)) {
            throw SocialOAuthRefused::noLongerPermitted('X no longer accepts this connection. It needs connecting again.');
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('X');
        }

        $rotated = $response->json('refresh_token');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            expiresIn: is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get('https://api.x.com/2/users/me');
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('X');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('X');
        }

        $id = trim((string) $response->json('data.id'));
        $name = trim((string) $response->json('data.name'));
        $username = trim((string) $response->json('data.username'));

        if ($id === '') {
            throw SocialOAuthRefused::unidentified('X');
        }

        return [new SocialAccount($id, $name !== '' ? $name : $username, $username !== '' ? $username : null)];
    }
}
