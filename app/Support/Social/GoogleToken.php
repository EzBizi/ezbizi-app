<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A usable access token for a connected Google account.
 *
 * ── Why this exists rather than a third copy ─────────────────────
 *
 * The refresh dance was written twice — once in the Local Services sync, once
 * in the Business Profile review sync — and the locations sync would have made
 * three. Three copies of "ask Google for a new token, and mark the connection
 * broken when it refuses" is three places to fix the day the refusal shape
 * changes, and two of them will be missed.
 *
 * The half that matters is not the HTTP call. It is what happens when Google
 * says no: the connection is marked broken with a sentence, so the screen can
 * say "connect it again" instead of showing an empty list. That behaviour is
 * worth having in exactly one place.
 *
 * ── Always refreshed, never cached ───────────────────────────────
 *
 * Access tokens last an hour and a sync runs whenever it runs, so there is no
 * useful window where a stored one is still good. Refreshing every time costs
 * one request and removes a whole class of "it worked this morning".
 */
final class GoogleToken
{
    private const TIMEOUT = 10;

    public const PAUSED = 'Google is paused for this business (Settings › Leads › Pause all Google calls).';

    /** The business's own switch, read at the connection's scope (branch, company or organization). */
    public static function paused(SocialConnection $connection): bool
    {
        $org = \App\Models\Organization::withoutGlobalScopes()->find($connection->organization_id);
        if ($org === null) {
            return false;
        }

        // Read inside the connection's own organization: a scheduled command or
        // a report asks for a token with no tenant set, and Settings are a
        // tenant's (0.0.0446 gate: AdsCost was refused every token).
        return (bool) app(\App\Support\Tenancy\TenantContext::class)->run($org, function () use ($connection, $org) {
            $scope = match ($connection->scope_type) {
                SocialConnection::SCOPE_FRANCHISE => \App\Models\Franchise::query()->find($connection->scope_id),
                SocialConnection::SCOPE_COMPANY => \App\Models\Company::query()->find($connection->scope_id),
                default => null,
            } ?? $org;

            return (bool) app(\App\Support\Settings\SettingsResolver::class)->get('leads.google_paused', $scope);
        });
    }

    /**
     * @param  string  $service  the `config/services.php` key holding the
     *                           OAuth client this connection was made with —
     *                           `google_ads`, `business_profile` or `youtube`. They are
     *                           deliberately separate registrations, so the
     *                           refresh must be signed by the right one.
     *
     * @throws RuntimeException
     */
    public static function forConnection(SocialConnection $connection, string $service): string
    {
        // Paused in Settings for this business: nothing goes to Google, and the
        // connection is not marked broken (A1 Leads takeover, 2026-10-16).
        if (self::paused($connection)) {
            throw new RuntimeException(self::PAUSED);
        }

        $refresh =(string) (($connection->credentials['refresh_token'] ?? '') ?: '');
        $label = self::label($service);

        if ($refresh === '') {
            throw new RuntimeException(
                "This {$label} connection has no lasting permission stored, so it cannot be "
                .'refreshed. It needs connecting again.'
            );
        }

        /*
         * The key has to resolve BEFORE anything is sent, because the failure
         * path below writes STATUS_BROKEN to the row.
         *
         * `$service` is a config key, not a label. Pass anything else and
         * `config("services.{$service}.client_id")` is null, Google is handed an
         * empty client, the refresh fails, and a healthy grant gets marked
         * broken with a sentence telling the customer to reconnect an account
         * that was never the problem. A typo should not be able to corrupt a
         * production row — it happened to A-1 Chimney's live connection from one
         * wrong argument in a one-off command.
         *
         * Named after the key the caller actually passed, so the fault is
         * legible as ours rather than Google's.
         */
        /*
         * A connection may carry its OWN OAuth client (Jose, 2026-10-15): A-1
         * Chimney's interim Business Profile connection is Rodrigo's grant, made
         * through HIS Cloud project (A1 Leads), which has the quota ours does
         * not yet. Never the global client: that would route every tenant's
         * consent through his project. When ours is approved, swapping is a
         * credential change on that row. Rodrigo can revoke it at any time;
         * the refresh then fails as invalid_grant and the row reads broken.
         */
        $own = $connection->credentials['client_id'] ?? null;
        $clientId = is_string($own) && $own !== '' ? $own : config("services.{$service}.client_id");
        $secret = is_string($own) && $own !== ''
            ? (string) ($connection->credentials['client_secret'] ?? '')
            : (string) config("services.{$service}.client_secret");

        if (! is_string($clientId) || $clientId === '') {
            throw new RuntimeException(
                "EzBizi has no OAuth client configured under services.{$service}, so this "
                .'connection cannot be renewed. That is a configuration fault at EzBizi\'s '
                .'end and says nothing about the account.'
            );
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'client_id' => (string) $clientId,
                    'client_secret' => $secret,
                    'refresh_token' => $refresh,
                    'grant_type' => 'refresh_token',
                ],
            );
        } catch (ConnectionException) {
            // Deliberately NOT marked broken. Google being unreachable for ten
            // seconds is a network problem, and marking the connection broken
            // for it would send somebody to reconnect an account that is fine.
            throw new RuntimeException('Google did not answer while renewing the connection.');
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
            /*
             * Only `invalid_grant` means the grant itself is gone — revoked,
             * expired, or the password changed. Google reports those three the
             * same way, which is why the sentence below does not guess between
             * them.
             *
             * Every other failure is about this MOMENT, not the grant: a 429
             * when the token endpoint is rate-limiting us, a 5xx from Google, a
             * 400 from a malformed request of ours. Marking broken for those is
             * worse than useless, because `broken` takes the row out of the
             * sweep that polls connected rows — so a connection killed by a
             * ten-second rate limit can never heal itself, and nobody finds out
             * until a customer asks where their leads went.
             *
             * That is not hypothetical either. Three live A-1 Chimney and
             * AngiSweep accounts were marked broken on 10-11 September during
             * the deliberate quota-exhaustion run for the Standard Access
             * application; the log shows HTTP 429 immediately before each one.
             * All three refresh tokens still renew and all three accounts still
             * read, and between them they lost three days of leads.
             *
             * ConnectionException a few lines above already takes this view.
             * A 503 is the same event as a dropped connection; only the layer
             * that reports it differs.
             */
            if ($response->json('error') !== 'invalid_grant') {
                throw new RuntimeException(
                    'Google would not renew this connection just now (HTTP '.$response->status().'). '
                    .'The connection has been left alone and the next sweep will try again.'
                );
            }

            $connection->forceFill([
                'status' => SocialConnection::STATUS_BROKEN,
                'status_reason' => 'Google would not renew this connection. It needs connecting again.',
            ])->save();

            throw new RuntimeException(
                'Google would not renew this connection. It needs connecting again.'
            );
        }

        return $access;
    }

    /**
     * Trade a consent callback's code for a grant: the one copy for the
     * Google Ads, Business Profile and YouTube connections.
     *
     * `code_verifier` is sent only when one is given. YouTube connects with
     * PKCE; the other two do not.
     *
     * @param  string  $service  the `config/services.php` key, as for forConnection()
     * @param  string  $network  the name a refusal shows the customer
     *
     * @throws SocialOAuthRefused
     */
    public static function exchange(
        string $service,
        string $network,
        string $code,
        string $redirectUri,
        ?string $verifier = null,
    ): SocialOAuthGrant {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'client_id' => (string) config("services.{$service}.client_id"),
                    'client_secret' => (string) config("services.{$service}.client_secret"),
                    'code' => $code,
                    ...($verifier === null ? [] : ['code_verifier' => $verifier]),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                ],
            );
        } catch (ConnectionException) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        if ($response->failed()) {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        if (! is_string($access) || $access === '') {
            throw SocialOAuthRefused::exchangeFailed($network);
        }

        if (! is_string($refresh) || $refresh === '') {
            throw SocialOAuthRefused::noLastingPermission($network);
        }

        return new SocialOAuthGrant(
            accessToken: $access,
            refreshToken: $refresh,
            expiresIn: is_numeric($response->json('expires_in'))
                ? (int) $response->json('expires_in')
                : null,
            extra: ['account_email' => self::idTokenEmail($response->json('id_token'))],
        );
    }

    /**
     * The Google login a consent was given by, from the id_token the `openid
     * email` scopes add (Jose, 2026-11-02: three sign-ins made 37 rows and
     * only display names said whose). Read unverified: it came straight from
     * Google's token endpoint over TLS, not from the browser.
     */
    private static function idTokenEmail(mixed $idToken): ?string
    {
        $payload = is_string($idToken) ? explode('.', $idToken)[1] ?? '' : '';
        $email = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['email'] ?? null;

        return is_string($email) && $email !== '' ? strtolower($email) : null;
    }

    private static function label(string $service): string
    {
        return match ($service) {
            'google_ads' => 'Google Ads',
            'business_profile' => 'Business Profile',
            'youtube' => 'YouTube',
            default => 'Google',
        };
    }
}
