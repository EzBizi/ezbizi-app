<?php

namespace App\Support\Email;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talking to Microsoft about a sign-in — and nothing else.
 *
 * OAuth 2.0 authorization-code flow with PKCE (RFC 6749 + RFC 7636) as Entra ID
 * implements it. {@see MicrosoftOAuthSignIns} owns the state row;
 * {@see \App\Http\Controllers\Api\MicrosoftMailAuthController} owns the
 * decisions; this owns the wire.
 *
 * Scopes, named:
 *
 *   Mail.Read  — list, open, search. Also covers delta queries for arrivals.
 *   Mail.Send  — reply as this mailbox. Cannot read, cannot delete.
 *   offline_access — the refresh token. Without it the inbox dies in an hour.
 *   User.Read  — which account actually signed in (`GET /me`). That is the
 *                mismatch check; `login_hint` is only a nudge.
 *
 * Not asked for: Mail.ReadWrite, Mail.ReadWrite.Shared, or anything that can
 * delete or move mail. Read state stays in EzBizi's `message_seen` table.
 *
 * CALLBACK_PATH is a literal — `route()` would take the Host header.
 */
final class MicrosoftOAuth
{
    public const SCOPE_READ = 'Mail.Read';

    public const SCOPE_SEND = 'Mail.Send';

    public const SCOPE_OFFLINE = 'offline_access';

    /** Which mailbox the token actually opens — see {@see identify}. */
    public const SCOPE_PROFILE = 'User.Read';

    public const CALLBACK_PATH = '/api/v1/email/microsoft/callback';

    private const AUTHORIZE_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    /**
     * Microsoft has no Google-style token revoke. This is best-effort: a
     * sign-out request for the grant we must not keep after a mismatch. Silent
     * on failure, same argument as {@see GoogleOAuth::revoke}.
     */
    private const REVOKE_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/logout';

    private const PROFILE_ENDPOINT = 'https://graph.microsoft.com/v1.0/me';

    private const TIMEOUT = 10;

    /**
     * @throws MicrosoftOAuthRefused
     */
    public function redirectUri(): string
    {
        $configured = trim((string) config('services.microsoft_mail.redirect'));
        $parts      = $configured === '' ? false : parse_url($configured);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw MicrosoftOAuthRefused::notConfigured(
                'The web address Microsoft is meant to return people to has not been set up, so '
                .'there is nowhere for the sign-in to come back to.',
            );
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);

        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw MicrosoftOAuthRefused::notConfigured(
                'The web address Microsoft is meant to return people to is not a secure (https) '
                .'address, and Microsoft will not send anybody to an insecure one.',
            );
        }

        if (($parts['path'] ?? '') !== self::CALLBACK_PATH
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw MicrosoftOAuthRefused::notConfigured(
                'The web address Microsoft is meant to return people to does not point at this '
                .'EzBizi installation\'s sign-in page, so a sign-in would end up somewhere '
                .'else.',
            );
        }

        return $configured;
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    public function authorizeUrl(string $state, string $verifier, string $loginHint): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id'             => (string) config('services.microsoft_mail.client_id'),
            'redirect_uri'          => $this->redirectUri(),
            'response_type'         => 'code',
            'response_mode'         => 'query',
            'scope'                 => implode(' ', [
                self::SCOPE_READ,
                self::SCOPE_SEND,
                self::SCOPE_OFFLINE,
                self::SCOPE_PROFILE,
            ]),
            'prompt'                => 'consent',
            'state'                 => $state,
            'code_challenge'        => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'login_hint'            => $loginHint,
        ]);
    }

    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function newVerifier(): string
    {
        return Str::random(96);
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    public function exchange(string $code, string $verifier): MicrosoftOAuthGrant
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_ENDPOINT, [
                'client_id'     => (string) config('services.microsoft_mail.client_id'),
                'client_secret' => (string) config('services.microsoft_mail.client_secret'),
                'code'          => $code,
                'code_verifier' => $verifier,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri(),
            ]);
        } catch (ConnectionException) {
            throw MicrosoftOAuthRefused::exchangeFailed();
        }

        if ($response->failed()) {
            throw MicrosoftOAuthRefused::exchangeFailed();
        }

        $refresh = $response->json('refresh_token');
        $access  = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw MicrosoftOAuthRefused::exchangeFailed();
        }

        if (! is_string($refresh) || $refresh === '') {
            throw MicrosoftOAuthRefused::noLastingPermission();
        }

        return new MicrosoftOAuthGrant(
            refreshToken: $refresh,
            accessToken: $access,
            scopes: preg_split('/\s+/', trim((string) $response->json('scope')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    /**
     * The address of the mailbox this token actually opens.
     *
     * `mail` first, then `userPrincipalName`. Absence is never a match.
     */
    public function identify(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(self::TIMEOUT)
                ->get(self::PROFILE_ENDPOINT);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        foreach (['mail', 'userPrincipalName'] as $field) {
            $address = $response->json($field);

            if (is_string($address) && trim($address) !== '' && str_contains($address, '@')) {
                return strtolower(trim($address));
            }
        }

        return null;
    }

    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(self::TIMEOUT)->post(self::REVOKE_ENDPOINT, ['token' => $token]);
        } catch (\Throwable) {
            // Best effort. See GoogleOAuth::revoke.
        }
    }
}
