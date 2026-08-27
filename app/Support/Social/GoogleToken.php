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

    /**
     * @param  string  $service  the `config/services.php` key holding the
     *                           OAuth client this connection was made with —
     *                           `google_ads` or `business_profile`. They are
     *                           deliberately separate registrations, so the
     *                           refresh must be signed by the right one.
     *
     * @throws RuntimeException
     */
    public static function forConnection(SocialConnection $connection, string $service): string
    {
        $refresh = (string) (($connection->credentials['refresh_token'] ?? '') ?: '');
        $label   = self::label($service);

        if ($refresh === '') {
            throw new RuntimeException(
                "This {$label} connection has no lasting permission stored, so it cannot be "
                .'refreshed. It needs connecting again.'
            );
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'client_id'     => (string) config("services.{$service}.client_id"),
                    'client_secret' => (string) config("services.{$service}.client_secret"),
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
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
            // Google reports a revoked grant and an expired one the same way,
            // so the row says the one true thing — it will not renew — and the
            // screen offers to connect it again rather than guessing which.
            $connection->forceFill([
                'status'        => SocialConnection::STATUS_BROKEN,
                'status_reason' => 'Google would not renew this connection. It needs connecting again.',
            ])->save();

            throw new RuntimeException(
                'Google would not renew this connection. It needs connecting again.'
            );
        }

        return $access;
    }

    private static function label(string $service): string
    {
        return match ($service) {
            'google_ads' => 'Google Ads',
            'business_profile' => 'Business Profile',
            default => 'Google',
        };
    }
}
