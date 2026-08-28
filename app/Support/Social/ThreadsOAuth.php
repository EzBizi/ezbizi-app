<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Threads posting. Replies/DMs are not requested. */
final class ThreadsOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/threads/callback';

    /** @var string[] */
    public const SCOPES = [
        'threads_basic',
        'threads_content_publish',
    ];

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::Threads;
    }

    public function routeKey(): string
    {
        return 'threads';
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
        return MetaApp::threadsNotReadyBecause();
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.meta.threads_redirect'),
            self::CALLBACK_PATH,
            'Threads',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        return 'https://www.threads.net/oauth/authorize?'.http_build_query([
            'client_id'     => MetaApp::threadsId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(',', self::SCOPES),
            'state'         => $state,
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        try {
            $short = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://graph.threads.net/oauth/access_token',
                [
                    'client_id'     => MetaApp::threadsId(),
                    'client_secret' => MetaApp::threadsSecret(),
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUri(),
                    'code'          => $code,
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Threads');
        }

        if ($short->failed()) {
            throw SocialOAuthRefused::exchangeFailed('Threads');
        }

        $shortToken = $short->json('access_token');

        // Meta's callbacks arrive carrying only this id. See
        // MetaCallbacksController for what it is used to find.
        $metaUser = $short->json('user_id');
        $metaUser = is_scalar($metaUser) ? (string) $metaUser : null;

        if (! is_string($shortToken) || $shortToken === '') {
            throw SocialOAuthRefused::exchangeFailed('Threads');
        }

        try {
            $long = Http::timeout(self::TIMEOUT)->get('https://graph.threads.net/access_token', [
                'grant_type'    => 'th_exchange_token',
                'client_secret' => MetaApp::threadsSecret(),
                'access_token'  => $shortToken,
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
                ->get('https://graph.threads.net/v1.0/me', [
                    'fields' => 'id,username,name',
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('Threads');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('Threads');
        }

        $id = trim((string) $response->json('id'));
        $username = trim((string) $response->json('username'));
        $name = trim((string) $response->json('name'));

        if ($id === '' || $username === '') {
            throw SocialOAuthRefused::unidentified('Threads');
        }

        return [new SocialAccount($id, $name !== '' ? $name : $username, $username)];
    }
}
