<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
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
    private function send(string $method, string $path, array $payload = []): array
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

        if ($response->failed()) {
            throw new RuntimeException('Thumbtack refused it: '.$this->reason($response->json(), $response->status()));
        }

        return (array) $response->json();
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
