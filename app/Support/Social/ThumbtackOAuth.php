<?php

namespace App\Support\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Thumbtack's non-standard required parameter.
     *
     * Their authorization server is ORY Hydra, which uses `audience` to say
     * which API the token is for. Stock OAuth2 has no such parameter and no
     * library sends it by default, which is exactly why it is easy to miss:
     * everything looks right and the consent screen refuses.
     */
    public const AUDIENCE = 'urn:partner-api';

    private const TIMEOUT = 10;

    public function provider(): SocialProvider
    {
        return SocialProvider::Thumbtack;
    }

    public function routeKey(): string
    {
        return 'thumbtack';
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

    /** ORY Hydra supports PKCE but their spec does not require it, and no live call has asked for it. */
    public function usesPkce(): bool
    {
        return false;
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
            // REQUIRED, and not part of stock OAuth2 — Thumbtack's own words:
            // "audience: Must be set to urn:partner-api." Their authorization
            // server refuses the request without it, so every connect would
            // have died at the consent screen with an error about the client
            // rather than about a missing parameter. Found in their
            // Authentication guide on 2026-09-08, before the first live
            // connect, because the OpenAPI document does not mention it at all.
            'audience' => self::AUDIENCE,
        ];

        if ($this->scopes() !== []) {
            $query['scope'] = implode(' ', $this->scopes());
        }

        return rtrim((string) config('services.thumbtack.authorize_url'), '?')
            .'?'.http_build_query($query);
    }

    /**
     * A token request, authenticated the one way their client accepts.
     *
     * ── HTTP Basic, not credentials in the body ─────────────────────
     *
     * Both are legal OAuth 2.0 (`client_secret_basic` and `client_secret_post`)
     * and a client registration names which one it will take. Thumbtack's takes
     * Basic, and refuses the other with `invalid_client` — whose stock
     * description reads "unknown client, no client authentication included, or
     * unsupported authentication method", so it looks exactly like a wrong
     * secret. Every connect died here on 2026-09-08 and the screen blamed the
     * account.
     *
     * The tell is in the tail of their message, which the stock text buries:
     * "supports client authentication method 'client_secret_basic', but method
     * 'client_secret_post' was requested".
     *
     * The credentials must NOT also be repeated in the body. Hydra treats
     * credentials in two places as a malformed request.
     */
    private function token(): PendingRequest
    {
        return Http::asForm()
            ->timeout(self::TIMEOUT)
            ->withBasicAuth(
                (string) config('services.thumbtack.client_id'),
                (string) config('services.thumbtack.client_secret'),
            );
    }

    /**
     * Write down why Thumbtack said no.
     *
     * ── Why this exists, given the tenant is told nothing ───────────
     *
     * The sentence a person sees is deliberately vague — a failed sign-in is
     * not their fault and not theirs to fix. But on 2026-09-08 the log said
     * NOTHING EITHER, so a connect that failed for a fixable reason left no
     * evidence of which of five reasons it was: a wrong secret, an
     * unregistered redirect URI, a missing audience, an expired code, or the
     * client auth method it actually turned out to be.
     *
     * Diagnosing it needed a hand-built probe against their token endpoint,
     * and the answer was sitting in a response body nobody had kept. This
     * keeps it. Same lesson as GoogleApiRefusal, learned the same week.
     *
     * The body carries no token on a failure — it is an error document — so
     * there is nothing here that must not be written down.
     */
    private function refused(string $step, ?Response $response): void
    {
        Log::warning('Thumbtack refused the '.$step.'.', [
            'status' => $response?->status(),
            'error'  => $response?->json('error'),
            // The whole description. Theirs puts the actionable half at the
            // END of a long stock sentence, and a truncated log would have
            // cut off the only part that named the problem.
            'description' => $response?->json('error_description'),
        ]);
    }

    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant
    {
        $form = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri(),
        ];

        try {
            $response = $this->token()->post((string) config('services.thumbtack.token_url'), $form);
        } catch (ConnectionException) {
            $this->refused('exchange', null);

            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        if ($response->failed()) {
            $this->refused('exchange', $response);

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
     * Trade a refresh token for a new pair.
     *
     * ── Why this had to exist before the first real connect ──────
     *
     * A Thumbtack access token lasts ONE HOUR. Nothing in EzBizi refreshed a
     * non-Google social connection, so a Thumbtack account would have worked
     * for an hour and then failed at everything after: the nightly phone sync,
     * every webhook-triggered fetch, every reply. It would have read as the
     * connection having broken rather than a token having expired, and the only
     * fix anybody would find is reconnecting — daily.
     *
     * ── The rotation, which is the part that bites ───────────────
     *
     * Thumbtack's refresh tokens are SINGLE USE. Each exchange returns a new
     * one and invalidates the old, with a 60-second grace window for a retry
     * that failed to save. So the new refresh token MUST be stored: keeping the
     * old one turns a working connection into a dead one on the SECOND refresh,
     * an hour after the first, which is the kind of bug that reads as
     * intermittent and is not.
     *
     * Their window is 180 days of inactivity, reset on every exchange.
     */
    public function refresh(string $refreshToken): SocialOAuthGrant
    {
        try {
            $response = $this->token()->post((string) config('services.thumbtack.token_url'), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'audience'      => self::AUDIENCE,
            ]);
        } catch (ConnectionException) {
            $this->refused('refresh', null);

            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        if ($response->failed()) {
            $this->refused('refresh', $response);

            // Not a hiccup: the grant itself is dead, and only a new sign-in
            // brings it back. ThumbtackClient marks the connection broken on it.
            if ($response->json('error') === 'invalid_grant') {
                throw SocialOAuthRefused::noLongerPermitted('Thumbtack no longer accepts this connection. It needs connecting again.');
            }

            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed('Thumbtack');
        }

        // Rotation means the answer normally carries a NEW refresh token.
        // Falling back to the one we sent is correct for the grace-window
        // retry and wrong to assume, so it is a fallback and not the default.
        $rotated = $response->json('refresh_token');

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
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
