<?php

namespace App\Support\Automations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calling a URL a tenant chose (plan §5.5 — "outbound webhooks are the sharp edge").
 *
 * ── What this is defending against ───────────────────────────────
 *
 * A tenant-configured webhook URL means a tenant can make OUR server issue HTTP
 * requests from inside our network. Point it at 169.254.169.254 and it reads the
 * cloud instance metadata — on a box using instance roles that is credentials.
 * Point it at 127.0.0.1 and it reaches anything bound to localhost. Point it at a
 * 10.x address and it reaches the rest of the estate.
 *
 * ── Why it checks resolved IPs and not the hostname ──────────────
 *
 * A hostname allow-list or a "no 127.0.0.1" string check is defeated by DNS:
 * anyone can point evil.example.com at 169.254.169.254, and the string looks
 * perfectly ordinary. So the host is RESOLVED first and every address it answers
 * with is checked. Redirects are not followed for the same reason — a permitted
 * URL that 302s to the metadata endpoint would otherwise walk straight past this.
 *
 * The approved address is pinned for this transfer. The URL retains its hostname
 * for TLS SNI/certificate verification; no proxy may resolve it a second time.
 */
class OutboundWebhook
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * Ranges nothing tenant-configured may reach.
     *
     * Named rather than computed so each one can say why it is here.
     */
    private const BLOCKED = [
        '127.0.0.0/8',      // loopback — everything bound to localhost
        '::1/128',
        '10.0.0.0/8',       // private
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',   // link-local — the cloud metadata endpoint lives here
        'fe80::/10',
        '100.64.0.0/10',    // carrier NAT; the Tailscale range on these boxes
        '0.0.0.0/8',
        '192.0.0.0/24',
        '192.0.2.0/24',     // documentation ranges, but they resolve somewhere
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
        'fc00::/7',         // unique local
        'ff00::/8',
        '::/128',           // the unspecified address; some stacks route it to loopback
        '64:ff9b::/96',     // NAT64 — a v6 wrapper around an arbitrary v4 address
    ];

    /**
     * @param  array  $payload  sent as JSON
     * @param  string|null  $secret  signs the body, so the receiver can tell our
     *                               request from anyone else's
     * @return array{ok: bool, status: int|null, body: string|null, error: string|null}
     */
    public function send(string $url, array $payload, ?string $secret = null): array
    {
        try {
            $approved = $this->approve($url);
            if (! extension_loaded('curl')) {
                throw new RuntimeException('Webhooks require the pinned cURL transport.');
            }
        } catch (RuntimeException $e) {
            return ['ok' => false, 'status' => null, 'body' => null, 'error' => $e->getMessage(), 'retryable' => false];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'EzBizi-Automations/1.0',
        ];

        if ($secret !== null) {
            // HMAC over the exact bytes sent, with a timestamp, so the receiver
            // can reject replays as well as forgeries.
            $timestamp = now()->timestamp;
            $headers['X-EzBizi-Timestamp'] = (string) $timestamp;
            $headers['X-EzBizi-Signature'] = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                /*
                 * Abort on size, at the transport.
                 *
                 * Truncating after the fact only bounded what got STORED — the
                 * whole body was buffered into a PHP string first, so a
                 * tenant-chosen endpoint replying with a few gigabytes could
                 * exhaust the queue worker's memory. This refuses the transfer
                 * instead of surviving it.
                 */
                ->withOptions([
                    'verify' => true,
                    'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => [$approved]],
                    'on_headers' => function ($response) {
                        $length = (int) ($response->getHeaderLine('Content-Length') ?: 0);

                        if ($length > self::MAX_RESPONSE_BYTES) {
                            throw new RuntimeException(
                                "Refused: the endpoint answered with {$length} bytes."
                            );
                        }
                    },
                    'progress' => function ($downloadTotal, $downloaded) {
                        if ($downloaded > self::MAX_RESPONSE_BYTES) {
                            throw new RuntimeException('Refused: the response exceeded the size cap.');
                        }
                    },
                ])
                // Never follow a redirect: a permitted URL that 302s to the
                // metadata endpoint would walk straight past the check above.
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($url);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                // Truncated: the response is only kept for diagnostics, and an
                // endpoint returning a gigabyte should not be able to fill the
                // run log with it.
                'body' => substr($response->body(), 0, self::MAX_RESPONSE_BYTES),
                'error' => $response->successful() ? null : "HTTP {$response->status()}",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => null, 'error' => $e->getMessage(),
                'retryable' => $e instanceof ConnectionException];
        }
    }

    /** @throws RuntimeException when the URL must not be called */
    public function assertAllowed(string $url): void
    {
        $this->approve($url);
    }

    /** Resolve once, validate every answer, and return cURL's pinned destination. */
    private function approve(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('That is not a URL this can call.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            // Plain HTTP puts the signed payload — which is customer data — on
            // the wire in clear, and there is no reason to allow it in 2026.
            throw new RuntimeException('Webhook URLs must be https.');
        }

        // parse_url hands back an IPv6 literal still wrapped in its brackets —
        // https://[::1]/ gives '[::1]', which is not an IP as far as
        // FILTER_VALIDATE_IP is concerned. Left in, a loopback address would be
        // refused for the wrong reason (it "does not resolve") and a perfectly
        // ordinary public IPv6 host would be refused too.
        $host = trim($parts['host'], '[]');

        $addresses = $this->resolve($host);
        foreach ($addresses as $ip) {
            foreach (self::BLOCKED as $range) {
                if ($this->inRange($ip, $range)) {
                    throw new RuntimeException(
                        "That URL resolves to {$ip}, which is inside {$range} — an "
                        .'internal address. Webhooks may only reach the public internet.'
                    );
                }
            }
        }

        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return $host.':'.($parts['port'] ?? 443).':'.$address;
    }

    /** @return string[] */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );

        if ($addresses === []) {
            throw new RuntimeException(
                "'{$host}' does not resolve. A URL that cannot be resolved cannot be "
                .'checked, and calling it unchecked is the thing this prevents.'
            );
        }

        return $addresses;
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = $this->normalise($ip);
        $subnetBin = @inet_pton($subnet);

        // Different families never match — comparing a v4 address against a v6
        // range byte by byte would produce nonsense.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * Fold an IPv4-mapped IPv6 address back to plain IPv4.
     *
     * `::ffff:169.254.169.254` and `169.254.169.254` are the same destination.
     * Without this they are not the same STRING and, worse, not the same LENGTH —
     * 16 bytes against a 4-byte range — so the family check above returned false
     * for every IPv4 range and the address sailed through to the metadata
     * endpoint. Writing the URL that way is a one-character change for an
     * attacker and was a complete bypass.
     *
     * ::ffff:0:0/96 is the mapped prefix; ::/96 is the deprecated
     * v4-compatible form, which some resolvers still produce.
     *
     * @return string|false packed form, folded to 4 bytes where applicable
     */
    private function normalise(string $ip): string|false
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $packed;
        }

        $mapped = str_repeat("\x00", 10)."\xff\xff";
        $compatible = str_repeat("\x00", 12);

        foreach ([$mapped, $compatible] as $prefix) {
            if (strncmp($packed, $prefix, 12) === 0) {
                $v4 = substr($packed, 12);

                // ::/128 itself is not a v4-compatible address, it is the
                // unspecified address — and it is blocked by its own range.
                if ($prefix === $compatible && $v4 === "\x00\x00\x00\x00") {
                    return $packed;
                }

                return $v4;
            }
        }

        return $packed;
    }
}
