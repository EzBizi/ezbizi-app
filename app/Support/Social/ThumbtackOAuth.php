<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thumbtack, as a lead source for EZ Leads.
 *
 * A Pro connects the Thumbtack account they already have, and their Thumbtack
 * enquiries appear beside the rest of their leads. EzBizi reads; it does not
 * post, and it never aggregates one tenant's leads into another's view.
 *
 * ── The endpoints, and where they finally came from ──────────────
 *
 * This class shipped on 2026-08-30 with NO endpoint defaults at all, because
 * Thumbtack's Partner API is gated and we had applied without receiving the
 * documentation. Guessing them from the shape of other OAuth2 providers would
 * have compiled, passed a test against a fake, and failed against Thumbtack
 * while looking like working code.
 *
 * Credentials arrived 2026-09-03 and the guess was never needed: the real
 * values are in their own OpenAPI document, at
 * api.thumbtack.com/docs/thumbtack_api_latest.json under `HydraOAuth`. It IS
 * a plain authorisation-code flow, so the shape here was right; the addresses
 * now come from them rather than from us. They live in config with real
 * defaults — see config/services.php for what each one is and why the API host
 * is not the auth host.
 *
 * ── Staging ──────────────────────────────────────────────────────
 *
 * Thumbtack issues staging credentials against a separate redirect URI, so the
 * base URL is not assumed from APP_URL alone: `THUMBTACK_REDIRECT_URI` names it
 * outright, which is what lets dev.ezbizi.com and app.ezbizi.com hold different
 * credentials at the same time.
 */
final class ThumbtackOAuth implements SocialOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/thumbtack/callback';

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::Thumbtack;
    }

    public function routeKey(): string
    {
        return 'thumbtack';
    }

    public function callbackPath(): string
    {
        return self::CALLBACK_PATH;
    }

    /**
     * Named in config, so an installation can widen them without a deploy.
     *
     * The default is only what EzBizi calls today — identify, and the two
     * associate-phone-number scopes. config/services.php says why reading
     * leads is deliberately not in that list yet.
     *
     * @return string[]
     */
    public function scopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.thumbtack.scopes', '')),
        )));
    }

    public function usesPkce(): bool
    {
        return (bool) config('services.thumbtack.pkce', false);
    }

    public function requiresRefreshToken(): bool
    {
        // Their own words in PINT-4039: "confirm your team can implement
        // OAuth 2.0 (Client IDs, token refreshes)". A connection that dies
        // silently when the first access token expires is the failure this
        // prevents.
        return true;
    }

    public function notReadyBecause(): ?string
    {
        if (! RedirectUri::credentialsPresent(
            config('services.thumbtack.client_id'),
            config('services.thumbtack.client_secret'),
        )) {
            return 'EzBizi has no Thumbtack app set up on this installation yet, so a Thumbtack '
                .'account cannot be connected. Nothing is wrong with the account — the missing '
                .'piece is at EzBizi\'s end.';
        }

        // These carry real defaults now, so an empty one means somebody blanked
        // it in .env rather than that Thumbtack has not told us. Still checked:
        // an empty authorize_url opens a window to nowhere, and "nothing
        // happened" is the least diagnosable failure there is.
        foreach (['authorize_url', 'token_url', 'api_base'] as $key) {
            if (trim((string) config('services.thumbtack.'.$key)) === '') {
                return 'EzBizi has Thumbtack credentials but one of the Thumbtack API addresses '
                    .'is blank on this installation, so connecting cannot be completed. The '
                    .'missing piece is at EzBizi\'s end.';
            }
        }

        return null;
    }

    public function redirectUri(): string
    {
        return RedirectUri::fromConfig(
            (string) config('services.thumbtack.redirect'),
            self::CALLBACK_PATH,
            'Thumbtack',
        );
    }

    public function authorizeUrl(string $state, ?string $verifier = null): string
    {
        $query = [
            'client_id'     => (string) config('services.thumbtack.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'state'         => $state,
        ];

        if ($this->scopes() !== []) {
            $query['scope'] = implode(' ', $this->scopes());
        }

        if ($verifier !== null && $this->usesPkce()) {
            $query['code_challenge']        = Pkce::challenge($verifier);
            $query['code_challenge_method'] = 'S256';
        }

        return rtrim((string) config('services.thumbtack.authorize_url'), '?')
            .'?'.http_build_query($query);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $form = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'client_id'     => (string) config('services.thumbtack.client_id'),
            'client_secret' => (string) config('services.thumbtack.client_secret'),
        ];

        if ($verifier !== null && $this->usesPkce()) {
            $form['code_verifier'] = $verifier;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post((string) config('services.thumbtack.token_url'), $form);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        $access  = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        if (! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::noLastingPermission('Thumbtack');
        }

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: $refresh,
            expiresIn: is_numeric($response->json('expires_in'))
                ? (int) $response->json('expires_in')
                : null,
        );
    }

    /**
     * The businesses this Thumbtack login administers.
     *
     * A PLURAL, and that is the point. One Thumbtack login can hold several
     * businesses, and `businessID` is what every other call is addressed to —
     * the associate phone numbers are per business, not per login. Returning
     * one account per business is what lets a franchise connect the branch it
     * means rather than whichever one happened to come back first.
     *
     * @return SocialAccount[]
     */
    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get(config('services.thumbtack.api_base').'/v4/businesses', ['limit' => 20]);
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        $accounts = [];

        foreach ((array) $response->json('data', []) as $business) {
            $id = trim((string) ($business['businessID'] ?? ''));

            if ($id === '') {
                continue;
            }

            $name = trim((string) ($business['name'] ?? ''));

            $accounts[] = new SocialAccount($id, $name !== '' ? $name : $id);
        }

        if ($accounts === []) {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        return $accounts;
    }
}
