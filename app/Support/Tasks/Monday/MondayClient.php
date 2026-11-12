<?php

namespace App\Support\Tasks\Monday;

use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Monday's GraphQL API, read-only, as the person who connected Monday
 * (SocialProvider::Monday). Monday answers HTTP 200 with an `errors` array for
 * most refusals, so the body is what is checked, not the status.
 *
 * Read only, on purpose (desk, 2026-11-12: "Still no writes to Monday"): a
 * query that starts with `mutation` is refused here, before it is sent.
 */
final class MondayClient
{
    public const API = 'https://api.monday.com/v2';

    public function __construct(private readonly string $token) {}

    public static function for(SocialConnection $connection): self
    {
        $token = ((array) ($connection->credentials ?? []))['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new MondayRefused('This Monday connection holds no token. It needs connecting again.');
        }

        return new self($token);
    }

    /** @return array<string, mixed> the `data` of the answer */
    public function query(string $query, array $variables = []): array
    {
        if (preg_match('/^\s*mutation\b/i', $query)) {
            throw new RuntimeException('EzBizi does not write to Monday.');
        }
        $response = Http::withHeaders(['Authorization' => $this->token])
            ->acceptJson()->timeout(60)
            ->post(self::API, ['query' => $query, 'variables' => (object) $variables]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new MondayRefused('Monday no longer accepts this connection. It needs connecting again.');
        }
        $errors = $response->json('errors') ?? $response->json('error_message');
        if ($response->failed() || $errors) {
            throw new RuntimeException('Monday refused the read: '.mb_substr(json_encode($errors ?: $response->status()), 0, 300));
        }

        return (array) $response->json('data');
    }
}
