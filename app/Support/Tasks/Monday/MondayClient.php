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

    /** @var \Closure(): string|null asks for the token anew (a connection's client) */
    private ?\Closure $fresh = null;

    public function __construct(private string $token) {}

    /**
     * A client on the connection's token, asked of MondayToken before every
     * request: a run longer than the token's life (the 11-13 import, ~2 h)
     * otherwise kept the first token after the 10-minute sync had rotated it,
     * and Monday refused every board after that.
     */
    public static function for(SocialConnection $connection): self
    {
        $client = new self(app(MondayToken::class)->for($connection));
        // Re-read without the tenant scope: a command may run with no tenant in context.
        $client->fresh = fn (): string => app(MondayToken::class)->for(
            SocialConnection::query()->withoutGlobalScopes()->findOrFail($connection->getKey()));

        return $client;
    }

    private function token(bool $renew = false): string
    {
        if ($this->fresh !== null && ($renew || $this->askedAt === null || $this->askedAt < time() - 60)) {
            $this->token = ($this->fresh)();
            $this->askedAt = time();
        }

        return $this->token;
    }

    private ?int $askedAt = null;

    /** @return array<string, mixed> the `data` of the answer */
    public function query(string $query, array $variables = []): array
    {
        if (preg_match('/^\s*mutation\b/i', $query)) {
            throw new RuntimeException('EzBizi does not write to Monday.');
        }
        // Monday's budget (complexity per minute, requests per minute) answers "wait N seconds":
        // a whole-board read (NW: ~1,000 queries) waits and goes on rather than failing midway.
        $renewed = 0;
        for ($attempt = 1; ; $attempt++) {
            $response = Http::withHeaders(['Authorization' => $this->token(renew: $renewed === 1)])
                ->acceptJson()->timeout(60)
                ->post(self::API, ['query' => $query, 'variables' => (object) $variables]);
            $wait = $response->status() === 429 ? (int) ($response->header('Retry-After') ?: 30)
                : (int) collect((array) $response->json('errors'))->map(fn ($e) => in_array($e['extensions']['code'] ?? null, ['COMPLEXITY_BUDGET_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'ComplexityException'], true)
                    ? (int) ($e['extensions']['retry_in_seconds'] ?? 30) : 0)->max();
            // Refused once: the token may have been rotated under us; ask for it again, once.
            if (in_array($response->status(), [401, 403], true) && $this->fresh !== null && $renewed === 0) {
                $renewed = 1;

                continue;
            }
            $renewed = $renewed === 1 ? 2 : $renewed;
            if ($wait <= 0 || $attempt >= 5) {
                break;
            }
            sleep(min(65, max(1, $wait)));
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new MondayRefused(MondayRefused::RECONNECT);
        }
        $errors = $response->json('errors') ?? $response->json('error_message');
        if ($response->failed() || $errors) {
            throw new RuntimeException('Monday refused the read: '.mb_substr(json_encode($errors ?: $response->status()), 0, 300));
        }

        return (array) $response->json('data');
    }
}
