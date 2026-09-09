<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The plumbing every Thumbtack call shares: a token, a host, and a sentence.
 *
 * ── Why this exists rather than a method on each class ───────────
 *
 * Four surfaces talk to Thumbtack — associate phone numbers, negotiations,
 * messages and webhooks — and each one needs the same three things: the access
 * token off the connection row, the API host for THIS installation (staging and
 * production are different hosts, and staging keys against the production host
 * fail as an authorisation error that reads like bad keys), and Thumbtack's own
 * error text rather than "HTTP 400".
 *
 * Four copies of that is four places for the staging/production switch to be
 * forgotten in. One copy is one.
 *
 * ── Every path is per business ───────────────────────────────────
 *
 * Almost everything here is addressed to `/v4/businesses/{businessID}/...`, and
 * the businessID comes off the connection rather than from the caller.
 * A wrong id does not fail — it reads and writes a DIFFERENT business's data,
 * which is the one mistake worth making structurally impossible.
 */
final class ThumbtackClient
{
    private const TIMEOUT = 20;

    public function __construct(private readonly SocialConnection $connection) {}

    /** The connected business's id, which most paths are built from. */
    public function businessId(): string
    {
        return (string) $this->connection->remote_id;
    }

    /** `/v4/businesses/{id}` with whatever follows appended. */
    public function businessPath(string $suffix = ''): string
    {
        return '/v4/businesses/'.$this->businessId().$suffix;
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('put', $path, $payload);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('delete', $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = [], bool $mayRefresh = true): array
    {
        $url = rtrim((string) config('services.thumbtack.api_base'), '/').$path;

        try {
            $request = Http::withToken($this->token())->timeout(self::TIMEOUT)->asJson();

            $response = $method === 'get'
                ? $request->get($url, $payload)
                : ($payload === [] ? $request->{$method}($url) : $request->{$method}($url, $payload));
        } catch (ConnectionException) {
            throw new RuntimeException('EzBizi could not reach Thumbtack.');
        }

        /*
         * An hour-old token, refreshed once and retried.
         *
         * HERE rather than in each caller, because everything reaches Thumbtack
         * through this method: the nightly phone sync, the webhook fetches, the
         * replies. A Thumbtack access token lasts one hour, so without this a
         * connection works until lunchtime and then fails at everything.
         *
         * ONCE. A second 401 after a successful refresh is not an expiry — it
         * is revoked access or a missing scope — and retrying would turn a
         * clear refusal into a loop.
         */
        if ($response->status() === 401 && $mayRefresh && $this->refreshToken() !== null) {
            $this->refreshAccessToken();

            return $this->send($method, $path, $payload, mayRefresh: false);
        }

        if ($response->failed()) {
            throw new RuntimeException('Thumbtack refused it: '.$this->reason($response->json(), $response->status()));
        }

        return (array) $response->json();
    }

    /**
     * Swap the expired token for a live one, and give it to EVERY row holding it.
     *
     * ── One grant, many rows ─────────────────────────────────────
     *
     * This is the part that is not obvious and that cost a day. One Thumbtack
     * login administers several businesses, and `identify()` returns one account
     * per BUSINESS — so a single connect writes N connection rows, all carrying
     * THE SAME token pair. Jose's connect on 2026-09-09 made twelve.
     *
     * Thumbtack rotates the refresh token on every exchange and invalidates the
     * one just used. So when this saved to `$this->connection` alone, the first
     * row to refresh spent the shared token and left the other eleven holding a
     * value that was already dead. Measured an hour later: eleven rows sharing
     * one fingerprint, one row with a rotated pair, seventeen `invalid_grant`
     * refusals in the log, and eventually the whole family revoked — reuse
     * detection reading eleven replays of a spent token as a stolen one.
     *
     * The rotated pair therefore goes to every row in this organization that
     * held the token we just spent. Compared in PHP because `credentials` is an
     * encrypted cast: the ciphertext differs per row even for identical values,
     * so no WHERE clause can find them.
     *
     * ── Why a lock, and why re-read inside it ────────────────────
     *
     * Two workers can hit 401 on the same shared token at the same moment —
     * likely here, because a webhook burst is exactly when several rows go stale
     * together. Both would exchange it, one would win, and the loser would
     * trigger the same reuse detection this exists to avoid.
     *
     * The lock alone only narrows that. The RE-READ inside it closes it: by the
     * time we hold the lock somebody else may have rotated already, in which
     * case our token is spent and asking again would be the very replay we are
     * avoiding. So we reload, and if the stored token is no longer the one we
     * queued with, we take theirs instead of asking for another.
     */
    private function refreshAccessToken(): void
    {
        $spent = $this->refreshToken();

        if ($spent === null) {
            return;
        }

        $organizationId = $this->connection->organization_id;

        // 30s is longer than the exchange and short enough that a killed worker
        // does not strand the others behind a lock nobody will release.
        Cache::lock('thumbtack-refresh:'.$organizationId, 30)->block(15, function () use ($spent, $organizationId) {
            $this->connection->refresh();

            $current = $this->refreshToken();

            if ($current !== null && $current !== $spent) {
                // Somebody rotated while we queued. Their result is the live
                // one; asking again with our spent token is the replay that
                // gets the whole family revoked.
                return;
            }

            $grant = app(ThumbtackOAuth::class)->refresh($spent);

            $rows = SocialConnection::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('provider', SocialProvider::Thumbtack->value)
                ->get()
                ->filter(function (SocialConnection $row) use ($spent) {
                    $credentials = $row->getAttribute('credentials');

                    return is_array($credentials)
                        && ($credentials['refresh_token'] ?? null) === $spent;
                });

            foreach ($rows as $row) {
                $credentials = $row->getAttribute('credentials');
                $credentials = is_array($credentials) ? $credentials : [];

                $credentials['access_token'] = $grant->accessToken;

                if ($grant->refreshToken !== null && $grant->refreshToken !== '') {
                    $credentials['refresh_token'] = $grant->refreshToken;
                }

                /*
                 * Expiry lives INSIDE credentials, not in a column.
                 *
                 * `social_connections` has no `expires_at`; SocialConnection::connect()
                 * takes it as a key in the tokens array. Two of us read
                 * `$row->expires_at` while diagnosing this and both saw null —
                 * not an empty value but a missing attribute, which Eloquent
                 * answers with null rather than an error. That is worth the
                 * sentence: a null from a column that does not exist looks
                 * exactly like a null from one that does.
                 *
                 * Behaviour today is unchanged either way, because the 401 is
                 * what drives the retry. It is written so a later
                 * refresh-before-expiry sweep has something true to read.
                 */
                if ($grant->expiresIn !== null) {
                    $credentials['expires_at'] = now()->addSeconds($grant->expiresIn)->toIso8601String();
                }

                $row->setAttribute('credentials', $credentials);
                $row->save();
            }

            // This instance holds one of those rows; take the new value rather
            // than the copy loaded before the exchange.
            $this->connection->refresh();
        });
    }

    private function refreshToken(): ?string
    {
        $credentials = $this->connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['refresh_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Thumbtack's own words where there are any.
     *
     * Their errors nest the readable part in more than one shape depending on
     * the endpoint, and "HTTP 422" tells whoever reads it nothing about which
     * field to change.
     */
    private function reason(mixed $body, int $status): string
    {
        if (is_array($body)) {
            foreach ([['error', 'message'], ['message'], ['error'], ['detail']] as $keys) {
                $value = $body;

                foreach ($keys as $key) {
                    $value = is_array($value) ? ($value[$key] ?? null) : null;
                }

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return 'HTTP '.$status;
    }

    private function token(): string
    {
        $credentials = $this->connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('That Thumbtack connection has no usable token. Reconnect it.');
        }

        return $token;
    }
}
