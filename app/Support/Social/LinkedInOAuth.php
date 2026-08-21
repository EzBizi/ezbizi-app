<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn member posting (Share on LinkedIn). Company Page scopes are
 * requested when the Community Management product is on the app; they are
 * listed here so Connect is ready the day that product is approved.
 *
 * LinkedIn often omits a refresh token. Access tokens last 60 days.
 */
final class LinkedInOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/linkedin/callback';

    /** @var string[] */
    public const SCOPES = [
        'openid',
        'profile',
        'w_member_social',
        'w_organization_social',
        'rw_organization_admin',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::LinkedIn;
    }

    public function routeKey(): string
    {
        return 'linkedin';
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
        return false;
    }

    public function requiresRefreshToken(): bool
    {
        return false;
    }

    public function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(
            config('services.linkedin.client_id'),
            config('services.linkedin.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no LinkedIn app set up on this installation yet, so a LinkedIn '
            .'account cannot be connected. Nothing is wrong with the Page — the missing piece '
            .'is at EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.linkedin.redirect'),
            self::CALLBACK_PATH,
            'LinkedIn',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id'     => (string) config('services.linkedin.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'scope'         => implode(' ', self::SCOPES),
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://www.linkedin.com/oauth/v2/accessToken',
                [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'client_id'     => (string) config('services.linkedin.client_id'),
                    'client_secret' => (string) config('services.linkedin.client_secret'),
                    'redirect_uri'  => $this->redirectUri(),
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('LinkedIn');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('LinkedIn');
        }

        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('LinkedIn');
        }

        $refresh = $response->json('refresh_token');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            expiresIn: is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get('https://api.linkedin.com/v2/userinfo');
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('LinkedIn');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('LinkedIn');
        }

        $id = trim((string) $response->json('sub'));
        $name = trim((string) $response->json('name'));

        if ($id === '') {
            throw SocialOAuthRefused::unidentified('LinkedIn');
        }

        return [new SocialAccount($id, $name !== '' ? $name : $id)];
    }
}
