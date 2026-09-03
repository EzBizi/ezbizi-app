<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Support\Str;

/**
 * How EzBizi finds out anything happened on Thumbtack.
 *
 * ── Not a notification channel. The only channel ──────────────────
 *
 * The supply-side API has no endpoint that lists negotiations, messages or
 * reviews. Every one of them can be fetched by id and none of them can be
 * discovered. So a webhook is not "instant notifications" as a convenience over
 * polling — there is nothing to poll. Without a registered webhook EzBizi
 * learns of no lead, ever.
 *
 * That also means registration is part of connecting, not a setting somebody
 * finds later: an account connected without one is an account that silently
 * receives nothing.
 *
 * ── How the callback proves it is Thumbtack ──────────────────────
 *
 * Thumbtack does not sign its callbacks. It offers two ways to authenticate
 * instead, and they are both credentials WE choose and hand to them: HTTP Basic,
 * or a custom header with a value.
 *
 * This uses the custom header with a 64-character random value, and that choice
 * matters. Basic credentials travel in an `Authorization` header that proxies,
 * gateways and error trackers are more likely to log, and Laravel's own
 * exception reporting redacts a header it recognises by name. A header nobody
 * else uses is not swept up by middleware that thinks it knows what
 * `Authorization` means.
 *
 * The value is stored on the connection alongside the tokens, encrypted with
 * them, and compared with `hash_equals` on the way in. It is the ONLY thing
 * standing between a stranger and an injected lead, because the payload itself
 * carries no proof of origin.
 *
 * ── Why rotate() exists ──────────────────────────────────────────
 *
 * Because the secret can leak the way any secret does — a log, a screenshot, a
 * misconfigured proxy — and the alternative to rotating is deleting the webhook
 * and making a new one, which loses events in the gap. `PUT .../auth` replaces
 * the credential on a webhook that keeps running.
 */
final class ThumbtackWebhooks
{
    /**
     * What EzBizi asks to be told about.
     *
     * The preset-job events are deliberately absent: they belong to a Thumbtack
     * product EzBizi does not implement, and asking for events nothing handles
     * means a callback that does work to reach a branch that drops it.
     */
    public const EVENTS = [
        'NegotiationCreatedV4',
        'MessageCreatedV4',
        'ReviewCreatedV4',
    ];

    /** The header Thumbtack sends back to us, and the credential key on the row. */
    public const AUTH_HEADER = 'X-EzBizi-Thumbtack';

    public const SECRET_KEY = 'webhook_secret';

    public function __construct(
        private readonly ThumbtackClient $client,
        private readonly SocialConnection $connection,
    ) {}

    public static function for(SocialConnection $connection): self
    {
        return new self(new ThumbtackClient($connection), $connection);
    }

    /**
     * Register the callback, or bring an existing one back into line.
     *
     * Safe to run again: it looks first, so reconnecting an account does not
     * leave two webhooks delivering everything twice.
     *
     * @return array<string, mixed>
     */
    public function ensure(string $callbackUrl): array
    {
        $secret = $this->rememberSecret();

        foreach ($this->all() as $existing) {
            if (($existing['webhookURL'] ?? null) !== $callbackUrl) {
                continue;
            }

            // Ours already. Put the event list and the credential back where we
            // want them rather than making a second one beside it — a duplicate
            // webhook delivers every lead twice, and nothing upstream dedupes.
            $id = (string) $existing['webhookID'];

            $this->client->put($this->client->businessPath('/webhooks/'.$id), [
                'webhookURL' => $callbackUrl,
                'eventTypes' => self::EVENTS,
                'enabled'    => true,
            ]);

            $this->rotate($id, $secret);

            return ['webhookID' => $id, 'reused' => true];
        }

        $created = $this->client->post($this->client->businessPath('/webhooks'), [
            'webhookURL' => $callbackUrl,
            'eventTypes' => self::EVENTS,
            'enabled'    => true,
            'auth'       => ['header' => self::AUTH_HEADER, 'value' => $secret],
        ]);

        return ['webhookID' => (string) ($created['webhookID'] ?? ''), 'reused' => false];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $body = $this->client->get($this->client->businessPath('/webhooks'));

        // Their list endpoint has been seen answering under both keys.
        $rows = (array) ($body['data'] ?? $body['webhooks'] ?? []);

        return array_values(array_filter(
            $rows,
            static fn ($row) => is_array($row) && isset($row['webhookID']),
        ));
    }

    /** Replace the credential on a live webhook, without losing events. */
    public function rotate(string $webhookId, ?string $secret = null): string
    {
        $secret ??= $this->newSecret();

        $this->client->put($this->client->businessPath('/webhooks/'.$webhookId.'/auth'), [
            'header' => self::AUTH_HEADER,
            'value'  => $secret,
        ]);

        $this->storeSecret($secret);

        return $secret;
    }

    public function remove(string $webhookId): void
    {
        $this->client->delete($this->client->businessPath('/webhooks/'.$webhookId));
    }

    /**
     * Ask Thumbtack to send a specimen negotiation event.
     *
     * Worth having because the failure this catches is invisible otherwise: a
     * webhook that registers fine and whose callback never arrives, because the
     * URL is unreachable from outside. Nothing about registration proves the
     * round trip.
     */
    public function sendTestNegotiation(): void
    {
        $this->client->post($this->client->businessPath('/webhooks/test-negotiation'));
    }

    /** True when this header value is the one we gave Thumbtack. */
    public function accepts(?string $presented): bool
    {
        $secret = $this->secret();

        return $secret !== null
            && is_string($presented)
            && $presented !== ''
            // Constant time. A `===` here leaks the secret one character at a
            // time to anybody willing to measure, and the whole callback is
            // authenticated by this one comparison.
            && hash_equals($secret, $presented);
    }

    public function secret(): ?string
    {
        $credentials = $this->connection->getAttribute('credentials');
        $secret = is_array($credentials) ? ($credentials[self::SECRET_KEY] ?? null) : null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function rememberSecret(): string
    {
        $secret = $this->secret();

        if ($secret === null) {
            $secret = $this->newSecret();
            $this->storeSecret($secret);
        }

        return $secret;
    }

    private function newSecret(): string
    {
        return Str::random(64);
    }

    private function storeSecret(string $secret): void
    {
        $credentials = $this->connection->getAttribute('credentials');
        $credentials = is_array($credentials) ? $credentials : [];

        $credentials[self::SECRET_KEY] = $secret;

        // Through the model, so it lands in the same encrypted cast the tokens
        // live in rather than beside them in the clear.
        $this->connection->setAttribute('credentials', $credentials);
        $this->connection->save();
    }
}
