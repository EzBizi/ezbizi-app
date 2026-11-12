<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** TikTok Login Kit + Content Posting. Not ads, not Research API. */
final class TikTokOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/tiktok/callback';

    /** @var string[] */
    public const SCOPES = [
        'user.info.basic',
        'video.upload',
        'video.publish',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::TikTok;
    }

    public function routeKey(): string
    {
        return 'tiktok';
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
        if (RedirectUri::credentialsPresent(
            config('services.tiktok.client_key'),
            config('services.tiktok.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no TikTok app set up on this installation yet, so a TikTok '
            .'account cannot be connected. Nothing is wrong with the account — the missing '
            .'piece is at EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.tiktok.redirect'),
            self::CALLBACK_PATH,
            'TikTok',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key'    => (string) config('services.tiktok.client_key'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(',', self::SCOPES),
            'state'         => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://open.tiktokapis.com/v2/oauth/token/',
                [
                    'client_key'    => (string) config('services.tiktok.client_key'),
                    'client_secret' => (string) config('services.tiktok.client_secret'),
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUri(),
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('TikTok');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('TikTok');
        }

        $access  = $response->json('access_token') ?? $response->json('data.access_token');
        $refresh = $response->json('refresh_token') ?? $response->json('data.refresh_token');
        $openId  = $response->json('open_id') ?? $response->json('data.open_id');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('TikTok');
        }

        if (! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::noLastingPermission('TikTok');
        }

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: $refresh,
            // What the person left granted; the connect-time check reads it.
            scopes: SocialOAuthGrant::parseScopes($response->json('scope') ?? $response->json('data.scope')),
            expiresIn: is_numeric($response->json('expires_in') ?? $response->json('data.expires_in'))
                ? (int) ($response->json('expires_in') ?? $response->json('data.expires_in'))
                : null,
            extra: is_string($openId) ? ['open_id' => $openId] : [],
        );
    }

    /**
     * A fresh access token from the lasting one.
     *
     * TikTok access tokens last 24 hours; the refresh token lasts a year and
     * may come back rotated, so the caller must store what this returns.
     * Measured 2026-10-05: the only live connection had been "connected" since
     * 08-28 with an access token dead since 08-29, because nothing renewed it.
     */
    public function refresh(string $refreshToken): SocialOAuthGrant
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://open.tiktokapis.com/v2/oauth/token/',
                [
                    'client_key'    => (string) config('services.tiktok.client_key'),
                    'client_secret' => (string) config('services.tiktok.client_secret'),
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('TikTok');
        }

        $access = $response->json('access_token') ?? $response->json('data.access_token');

        if ($response->json('error') === 'invalid_grant') {
            throw SocialOAuthRefused::noLongerPermitted('TikTok no longer accepts this connection. It needs connecting again.');
        }

        if ($response->failed() || ! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('TikTok');
        }

        $rotated = $response->json('refresh_token') ?? $response->json('data.refresh_token');
        $expires = $response->json('expires_in') ?? $response->json('data.expires_in');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            expiresIn: is_numeric($expires) ? (int) $expires : null,
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                // Only fields `user.info.basic` covers. `username` needs
                // `user.info.profile`, and TikTok fails the WHOLE request when
                // one field is outside the granted scope — so asking for it
                // cost us display_name too, and every connection was named
                // after its own open_id.
                ->get('https://open.tiktokapis.com/v2/user/info/', [
                    'fields' => 'open_id,display_name',
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('TikTok');
        }

        $user = $response->json('data.user') ?? [];
        $id = trim((string) ($user['open_id'] ?? $grant->extra['open_id'] ?? ''));
        $name = trim((string) ($user['display_name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));

        if ($id === '') {
            throw SocialOAuthRefused::unidentified('TikTok');
        }

        return [new SocialAccount($id, $name !== '' ? $name : ($username !== '' ? $username : $id), $username !== '' ? $username : null)];
    }
}
