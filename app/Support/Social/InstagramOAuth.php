<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Instagram professional via Instagram Login (not Facebook Login).
 *
 * Messaging is not requested. Short-lived tokens are exchanged for long-lived.
 */
final class InstagramOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/instagram/callback';

    /** @var string[] */
    public const SCOPES = [
        'instagram_business_basic',
        'instagram_business_content_publish',
        // Instagram direct messages into Leads, and replying to them.
        'instagram_business_manage_messages',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::InstagramProfessional;
    }

    public function routeKey(): string
    {
        return 'instagram';
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
        return MetaApp::instagramNotReadyBecause();
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.meta.instagram_redirect'),
            self::CALLBACK_PATH,
            'Instagram',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => MetaApp::instagramId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $short = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://api.instagram.com/oauth/access_token',
                [
                    'client_id' => MetaApp::instagramId(),
                    'client_secret' => MetaApp::instagramSecret(),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirectUri(),
                    'code' => $code,
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Instagram');
        }

        if ($short->failed()) {
            throw SocialOAuthRefused::exchangeFailed('Instagram');
        }

        $shortToken = $short->json('access_token');

        if (! is_string($shortToken) || $shortToken === '') {
            throw SocialOAuthRefused::exchangeFailed('Instagram');
        }

        // Kept because Meta's deauthorize and data-deletion callbacks arrive
        // carrying only this id. Without it there is no way to find what the
        // person who removed the app had authorised.
        $metaUser = $short->json('user_id');
        $metaUser = is_scalar($metaUser) ? (string) $metaUser : null;

        try {
            $long = Http::timeout(self::TIMEOUT)->get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => MetaApp::instagramSecret(),
                'access_token' => $shortToken,
            ]);
        } catch (ConnectionException) {
            return new SocialOAuthGrant(
                accessToken: $shortToken,
                extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
            );
        }

        $access = is_string($long->json('access_token')) ? $long->json('access_token') : $shortToken;

        return new SocialOAuthGrant(
            accessToken: $access,
            expiresIn: is_numeric($long->json('expires_in')) ? (int) $long->json('expires_in') : null,
            extra: $metaUser === null ? [] : ['meta_user_id' => $metaUser],
        );
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get('https://graph.instagram.com/v21.0/me', [
                    'fields' => 'user_id,id,username,name',
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('Instagram');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('Instagram');
        }

        /*
         * `user_id`, not `id`. Under Instagram Login `id` is the app-scoped
         * id of the person; `user_id` is the professional ACCOUNT - the id
         * Meta's message webhooks carry and /{id}/media and /{id}/messages
         * expect (Meta's get-started page, checked 2026-09-28). Stored as
         * `id`, no Instagram message could ever find its connection.
         */
        $id = trim((string) ($response->json('user_id') ?? $response->json('id')));
        $username = trim((string) $response->json('username'));
        $name = trim((string) $response->json('name'));

        if ($id === '' || $username === '') {
            throw SocialOAuthRefused::unidentified('Instagram');
        }

        return [new SocialAccount($id, $name !== '' ? $name : $username, $username)];
    }
}
