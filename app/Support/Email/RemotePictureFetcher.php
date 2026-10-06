<?php

namespace App\Support\Email;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetch a remote image through our origin, to a host the guard has approved.
 *
 * ── Why this exists, after years of not fetching ─────────────────
 *
 * The sanitiser used to turn every `<img>` into a placeholder. The two
 * reasons were real: a remote image is a tracking pixel, and an `<img src>`
 * is an outbound request to a host the sender chose (SSRF). The owner has
 * now said pictures should load. Tracking is accepted as the cost of seeing
 * them. SSRF is not: the browser never sees the sender's URL, this class
 * dials the address {@see RemoteHostGuard} approved, and private /
 * link-local / metadata hosts are refused the same way an IMAP form is.
 *
 * Redirects are followed only after the next Location is judged too. A 302
 * to 169.254.169.254 is the same attack as putting that address in `src`.
 */
final class RemotePictureFetcher
{
    public const HTTP_PORTS = [80, 443];

    public const MAX_BYTES = 2_097_152;

    public const MAX_REDIRECTS = 3;

    /** @var list<string> */
    public const ALLOWED_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly RemoteHostGuard $guard,
    ) {}

    public function fetch(string $url): EmailPicture
    {
        if (! extension_loaded('curl')) {
            throw new PictureUnavailable('Pictures require the pinned cURL transport.');
        }

        return $this->fetchFrom($url, 0);
    }

    /**
     * Several pictures AT ONCE, every one under the same guards as fetch().
     *
     * ── Why ──────────────────────────────────────────────────────
     *
     * Opening an HTML email inlines up to 24 pictures, and they were fetched
     * one after another inside the open. Measured on production 2026-10-06:
     * 412 pictures across 48 messages, all remote, median 318 ms per message
     * and 2.5 s at worst -- while sanitising the HTML itself took 7 ms. A dead
     * image host made it far worse: 24 pictures x a 5 s timeout is two
     * minutes. In parallel the wait is the slowest one, and a dead host costs
     * one timeout.
     *
     * ── Same guards, not copies of them ──────────────────────────
     *
     * Each request is built by `request()` and judged by `judge()`, the same
     * two methods fetch() uses: the host is approved and the connection pinned
     * to that address, the body streams through a size-capped sink, the
     * declared length is checked before any body, only image types are kept.
     * A redirect finishes on its own through `fetchFrom()`, which re-approves
     * the next host -- rare, and never worth weakening the hop check for.
     *
     * @param  array<array-key, string>  $urls
     * @return array<array-key, EmailPicture|null>  same keys; null = could not be loaded
     */
    public function fetchMany(array $urls): array
    {
        if (! extension_loaded('curl')) {
            return array_map(fn () => null, $urls);
        }

        $out = [];
        $ready = [];

        foreach ($urls as $key => $url) {
            try {
                $ready[$key] = ['url' => $url, ...$this->request($url)];
            } catch (PictureUnavailable|CannotReachHost) {
                $out[$key] = null;
            }
        }

        if ($ready !== []) {
            $responses = Http::pool(function (Pool $pool) use ($ready) {
                foreach ($ready as $key => $one) {
                    $this->pending($pool->as((string) $key), $one['options'])->get($one['url']);
                }
            });

            foreach ($ready as $key => $one) {
                $response = $responses[(string) $key] ?? null;

                try {
                    $out[$key] = $response instanceof Response
                        ? $this->judge($one['url'], $response, $response->body(), 0)
                        : null;
                } catch (PictureUnavailable|CannotReachHost|ConnectionException|TransferException) {
                    $out[$key] = null;
                } finally {
                    $one['sink']->close();
                }
            }
        }

        // In the order the caller gave, so a picture lands on its own <img>.
        return array_replace(array_map(fn () => null, $urls), $out);
    }

    /** Follow one picture from hop `$hop` on, re-approving every host. */
    private function fetchFrom(string $url, int $hop): EmailPicture
    {
        for ($current = $url; $hop <= self::MAX_REDIRECTS; $hop++) {
            ['options' => $options, 'sink' => $sink] = $this->request($current);

            try {
                $response = $this->pending(Http::getFacadeRoot(), $options)->get($current);
                $bytes = $response->body();
            } catch (ConnectionException|CannotReachHost|TransferException $e) {
                throw new PictureUnavailable('That picture could not be loaded.', previous: $e);
            } finally {
                $sink->close();
            }

            if ($response->redirect()) {
                $current = $this->next($current, $response);

                continue;
            }

            return $this->picture($response, $bytes);
        }

        throw new PictureUnavailable('That picture could not be loaded.');
    }

    /**
     * Approve the host and build the pinned, size-capped transfer for one hop.
     *
     * @return array{options: array<string, mixed>, sink: \Psr\Http\Message\StreamInterface}
     *
     * @throws CannotReachHost|PictureUnavailable
     */
    private function request(string $url): array
    {
        $parts = $this->parts($url);
        $approved = $this->guard->approve(
            $parts['host'],
            $parts['port'],
            self::HTTP_PORTS,
        );

        // cURL writes decoded bytes here, so compressed and chunked bodies
        // have the same cap even when Content-Length is missing or dishonest.
        $buffer = Utils::streamFor(fopen('php://temp', 'w+'));
        $received = 0;
        $sink = FnStream::decorate($buffer, [
            'write' => static function (string $bytes) use ($buffer, &$received): int {
                if ($received + strlen($bytes) > self::MAX_BYTES) {
                    throw new PictureUnavailable('That picture exceeds the size limit.');
                }
                $received += strlen($bytes);

                return $buffer->write($bytes);
            },
        ]);

        return [
            'sink' => $sink,
            'options' => [
                'allow_redirects' => false,
                'verify' => true,
                'proxy' => '',
                'sink' => $sink,
                'on_headers' => static function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new PictureUnavailable('That picture exceeds the size limit.');
                    }
                },
                'curl' => [
                    CURLOPT_RESOLVE => [sprintf(
                        '%s:%d:%s',
                        $approved->host,
                        $approved->port,
                        $approved->isIpV6() ? '['.$approved->address.']' : $approved->address,
                    )],
                ],
            ],
        ];
    }

    /**
     * The same request settings for a single fetch and for one slot of a pool.
     *
     * @param  \Illuminate\Http\Client\Factory|\Illuminate\Http\Client\PendingRequest  $on
     * @param  array<string, mixed>  $options
     */
    private function pending(object $on, array $options): PendingRequest
    {
        return $on->withOptions($options)
            ->timeout(RemoteHostGuard::CONNECT_TIMEOUT_SECONDS)
            ->connectTimeout(RemoteHostGuard::CONNECT_TIMEOUT_SECONDS)
            ->withHeaders(['Accept' => 'image/jpeg,image/png,image/gif,image/webp']);
    }

    /** A pooled first hop: either the picture, or the rest of its redirects alone. */
    private function judge(string $url, Response $response, string $bytes, int $hop): EmailPicture
    {
        if ($response->redirect()) {
            return $this->fetchFrom($this->next($url, $response), $hop + 1);
        }

        return $this->picture($response, $bytes);
    }

    private function next(string $current, Response $response): string
    {
        $next = $response->header('Location');

        if (! is_string($next) || $next === '') {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        return $this->absolute($current, $next);
    }

    private function picture(Response $response, string $bytes): EmailPicture
    {
        if (! $response->successful()) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! in_array($mime, self::ALLOWED_TYPES, strict: true)) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        return new EmailPicture($bytes, $mime === 'image/jpg' ? 'image/jpeg' : $mime);
    }

    /**
     * @return array{host: string, port: int, scheme: string}
     */
    private function parts(string $url): array
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || empty($parsed['host']) || empty($parsed['scheme'])) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $scheme = strtolower((string) $parsed['scheme']);

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $port = isset($parsed['port'])
            ? (int) $parsed['port']
            : ($scheme === 'https' ? 443 : 80);

        return [
            'host' => strtolower((string) $parsed['host']),
            'port' => $port,
            'scheme' => $scheme,
        ];
    }

    private function absolute(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($current);

        if (! is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $origin = $base['scheme'].'://'.$base['host']
            .(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim((string) ($base['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }
}
