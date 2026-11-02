<?php

namespace App\Support\Social;

use App\Support\Pkce;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * YouTube Data API v3. Separate Google OAuth client from Gmail — different
 * scopes, different redirect URI, same Host-header defence and PKCE.
 */
final class YouTubeOAuth implements SocialOAuth
{
    public const SCOPE_UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    /** @var string[] */
    // openid + email: which Google login this is (Jose, 2026-11-02).
    public const SCOPES = [self::SCOPE_UPLOAD, self::SCOPE_READONLY, 'openid', 'email'];

    public const CALLBACK_PATH = '/api/v1/social/youtube/callback';

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::YouTube;
    }

    public function routeKey(): string
    {
        return 'youtube';
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
            config('services.youtube.client_id'),
            config('services.youtube.client_secret'),
        )) {
            return null;
        }

        return 'EzBizi has no YouTube connection set up on this installation yet, so a '
            .'YouTube channel cannot be connected. Nothing is wrong with the channel — the '
            .'missing piece is at EzBizi\'s end.';
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.youtube.redirect'),
            self::CALLBACK_PATH,
            'YouTube',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        if ($verifier === null || $verifier === '') {
            throw SocialOAuthRefused::notConfigured('YouTube', 'A sign-in was started without PKCE.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'             => (string) config('services.youtube.client_id'),
            'redirect_uri'          => $this->redirectUri(),
            'response_type'         => 'code',
            'scope'                 => implode(' ', self::SCOPES),
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => $state,
            'code_challenge'        => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        return GoogleToken::exchange('youtube', 'YouTube', $code, $this->redirectUri(), (string) $verifier);
    }

    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet',
                    'mine' => 'true',
                ]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('YouTube');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('YouTube');
        }

        $item = $response->json('items.0') ?? [];
        $id = trim((string) ($item['id'] ?? ''));
        $title = trim((string) ($item['snippet']['title'] ?? ''));
        $custom = trim((string) ($item['snippet']['customUrl'] ?? ''));

        if ($id === '') {
            throw SocialOAuthRefused::unidentified(
                'YouTube',
                'That Google account has no YouTube channel EzBizi can post as, so nothing '
                .'has been connected.',
            );
        }

        return [new SocialAccount($id, $title !== '' ? $title : $id, $custom !== '' ? ltrim($custom, '@') : null)];
    }
}
