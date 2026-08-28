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
 * ── Why the endpoints are configuration, not constants ───────────
 *
 * Every other driver here hardcodes its URLs, because every other provider
 * publishes them. Thumbtack's Partner API is gated: the documentation arrives
 * with the credentials, and as of writing we have applied (PINT-4039) and have
 * neither. The three URLs below are therefore read from config with NO
 * defaults.
 *
 * The alternative was to guess them from the shape of other OAuth2 providers
 * and write plausible-looking constants. That would compile, pass a unit test
 * against a fake, and fail against Thumbtack — and it would look like working
 * code, so the first person to touch it would trust it. An empty config is
 * honest about not knowing; a wrong constant is not.
 *
 * When Thumbtack issues the documentation, this becomes six env values and no
 * code change. If their flow turns out not to be plain OAuth2 authorisation
 * code, this class is wrong and should be rewritten rather than bent.
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
     * Read-only, and named in config rather than here.
     *
     * We have not seen Thumbtack's scope vocabulary. Inventing scope strings
     * would fail at the authorize step with an error naming a scope that does
     * not exist, which reads as our bug rather than as a missing answer.
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

        // Credentials without endpoints is the state right after Thumbtack
        // issues keys and before anybody has read the documentation. Said
        // plainly, because otherwise Connect opens a window to nowhere.
        foreach (['authorize_url', 'token_url', 'account_url'] as $key) {
            if (trim((string) config('services.thumbtack.'.$key)) === '') {
                return 'EzBizi has Thumbtack credentials but not the API addresses to use them '
                    .'with. Thumbtack publishes those with the partner documentation; until they '
                    .'are on this installation, connecting cannot be completed.';
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
     * @return SocialAccount[]
     */
    public function identify(SocialOAuthGrant $grant): array
    {
        try {
            $response = Http::withToken($grant->accessToken)
                ->timeout(self::TIMEOUT)
                ->get((string) config('services.thumbtack.account_url'));
        } catch (ConnectionException) {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        // Read defensively across the shapes an account endpoint usually takes,
        // because we have not seen theirs. A wrong guess here costs a display
        // name, not a connection: the id is what everything else keys on.
        $body = $response->json();
        $body = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);

        $id = trim((string) ($body['id'] ?? $body['business_id'] ?? $body['user_id'] ?? ''));

        if ($id === '') {
            throw SocialOAuthRefused::unidentified('Thumbtack');
        }

        $name = trim((string) ($body['business_name'] ?? $body['name'] ?? $body['display_name'] ?? ''));

        return [new SocialAccount($id, $name !== '' ? $name : $id)];
    }
}
