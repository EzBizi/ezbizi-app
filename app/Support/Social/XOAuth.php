<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * X API v2 OAuth 2.0 with PKCE. Confidential client (Basic auth).
 * tweet.write + offline.access so a post can be scheduled unattended.
 */
final class XOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/x/callback';

    /** @var string[] */
    public const SCOPES = [
        'tweet.read',
        'tweet.write',
        'users.read',
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

    public function callbackPath(): string
    {
        return self::CALLBACK_PATH;
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

        return new SocialOAuthGrant(accessToken: $access, refreshToken: $refresh);
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
