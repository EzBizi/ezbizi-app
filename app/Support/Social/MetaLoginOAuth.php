<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta's own logins (Instagram Login, Threads): no PKCE, no refresh token,
 * and a short-lived code exchange swapped for a long-lived token. The steps
 * are the same; each subclass names its hosts, app and wording in constants.
 */
abstract class MetaLoginOAuth implements SocialOAuth
{
    private const TIMEOUT = 10;

    abstract protected function appId(): string;

    abstract protected function appSecret(): string;

    public function scopes(): array
    {
        return static::SCOPES;
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function requiresRefreshToken(): bool
    {
        return false;
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config(static::REDIRECT_CONFIG),
            static::CALLBACK_PATH,
            static::LABEL,
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return static::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', static::SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $short = Http::asForm()->timeout(self::TIMEOUT)->post(
                static::TOKEN_URL,
                [
                    'client_id' => $this->appId(),
                    'client_secret' => $this->appSecret(),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri(),
                    'code' => $code,
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed(static::LABEL);
        }

        if ($short->failed()) {
            throw SocialOAuthRefused::exchangeFailed(static::LABEL);
        }

        $shortToken = $short->json('access_token');

        if (! is_string($shortToken) || $shortToken === '') {
            throw SocialOAuthRefused::exchangeFailed(static::LABEL);
        }

        // Kept because Meta's deauthorize and data-deletion callbacks arrive
        // carrying only this id. Without it there is no way to find what the
        // person who removed the app had authorised.
        $metaUser = $short->json('user_id');
        $metaUser = is_scalar($metaUser) ? (string) $metaUser : null;

        // What the person left granted, for the connect-time check.
        $scopes = SocialOAuthGrant::parseScopes($short->json('permissions') ?? $short->json('data.0.permissions'));

        try {
            $long = Http::timeout(self::TIMEOUT)->get(static::LONG_TOKEN_URL, [
                'grant_type' => static::LONG_GRANT,
                'client_secret' => $this->appSecret(),
                'access_token' => $shortToken,
            ]);
        } catch (ConnectionException) {
            return new SocialOAuthGrant(
                accessToken: $shortToken,
                scopes: $scopes,
                extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
            );
        }

        $access = is_string($long->json('access_token')) ? $long->json('access_token') : $shortToken;

        return new SocialOAuthGrant(
            accessToken: $access,
            scopes: $scopes,
            expiresIn: is_numeric($long->json('expires_in')) ? (int) $long->json('expires_in') : null,
            extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
        );
    }

    /**
     * A long-lived token, renewed for another 60 days before it dies. Meta
     * gives these logins no refresh token: the live token itself is traded in
     * (`ig_refresh_token` / `th_refresh_token`), and only while it still works,
     * so RenewMetaLoginTokens does it daily well inside the window.
     */
    public function renew(string $longToken): SocialOAuthGrant
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get(static::REFRESH_URL, [
                'grant_type' => static::REFRESH_GRANT,
                'access_token' => $longToken,
            ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed(static::LABEL);
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed(static::LABEL);
        }

        return new SocialOAuthGrant(
            accessToken: $access,
            expiresIn: is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get(static::ME_URL, [
                    'fields' => static::ME_FIELDS,
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified(static::LABEL);
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified(static::LABEL);
        }

        $id = $this->accountId($response);
        $username = trim((string) $response->json('username'));
        $name = trim((string) $response->json('name'));

        if ($id === '' || $username === '') {
            throw SocialOAuthRefused::unidentified(static::LABEL);
        }

        return [new SocialAccount($id, $name !== '' ? $name : $username, $username)];
    }

    protected function accountId(Response $me): string
    {
        return trim((string) $me->json('id'));
    }
}
