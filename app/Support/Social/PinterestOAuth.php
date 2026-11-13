<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class PinterestOAuth implements SocialOAuth
{
    public const SCOPE_USER_READ = 'user_accounts:read';

    public const SCOPE_BOARDS_READ = 'boards:read';

    public const SCOPE_BOARDS_WRITE = 'boards:write';

    public const SCOPE_PINS_READ = 'pins:read';

    public const SCOPE_PINS_WRITE = 'pins:write';

    /** @var string[] */
    public const SCOPES = [
        self::SCOPE_USER_READ,
        self::SCOPE_BOARDS_READ,
        self::SCOPE_BOARDS_WRITE,
        self::SCOPE_PINS_READ,
        self::SCOPE_PINS_WRITE,
    ];

    public const CALLBACK_PATH = '/api/v1/social/pinterest/callback';

    private const AUTHORIZE_ENDPOINT = 'https://www.pinterest.com/oauth/';

    private const TOKEN_ENDPOINT = 'https://api.pinterest.com/v5/oauth/token';

    private const ACCOUNT_ENDPOINT = 'https://api.pinterest.com/v5/user_account';

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::Pinterest;
    }

    public function routeKey(): string
    {
        return 'pinterest';
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
            config('services.pinterest.client_id'),
            config('services.pinterest.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no Pinterest connection set up on this installation yet, so no '
            .'Pinterest account can be connected. Nothing is wrong with this account — the '
            .'missing piece is at EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.pinterest.redirect'),
            self::CALLBACK_PATH,
            'Pinterest',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => (string) config('services.pinterest.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        return OAuthTokens::exchanged(OAuthTokens::post($this->tokenRequest(), self::TOKEN_ENDPOINT, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ], 'Pinterest'), 'Pinterest');
    }

    /** Access tokens last 30 days; the refresh token renews them without the person. */
    public function refresh(string $refreshToken): SocialOAuthGrant
    {
        $response = OAuthTokens::post($this->tokenRequest(), self::TOKEN_ENDPOINT, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], 'Pinterest');

        return OAuthTokens::refreshed($response, 'Pinterest', $refreshToken, in_array($response->status(), [400, 401], strict: true));
    }

    private function tokenRequest(): PendingRequest
    {
        return Http::asForm()
            ->withBasicAuth(
                (string) config('services.pinterest.client_id'),
                (string) config('services.pinterest.client_secret'),
            )
            ->timeout(self::TIMEOUT);
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get(self::ACCOUNT_ENDPOINT);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('Pinterest');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('Pinterest');
        }

        $id = $response->json('id');
        $id = is_numeric($id) || is_string($id) ? trim((string) $id) : '';
        $username = is_string($response->json('username')) ? trim((string) $response->json('username')) : '';

        if ($id === '' || $username === '') {
            throw SocialOAuthRefused::unidentified('Pinterest');
        }

        $business = $response->json('business_name');
        $display = is_string($business) && trim($business) !== '' ? trim($business) : $username;

        return [new SocialAccount($id, $display, $username)];
    }
}
