<?php

namespace App\Support\Email;

use Illuminate\Http\Client\ConnectionException;
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
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = $this->parts($current);
            $approved = $this->guard->approve(
                $parts['host'],
                $parts['port'],
                self::HTTP_PORTS,
            );

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'verify'          => true,
                    'curl'            => [
                        CURLOPT_RESOLVE => [sprintf(
                            '%s:%d:%s',
                            $approved->host,
                            $approved->port,
                            $approved->address,
                        )],
                    ],
                ])
                    ->timeout(RemoteHostGuard::CONNECT_TIMEOUT_SECONDS)
                    ->connectTimeout(RemoteHostGuard::CONNECT_TIMEOUT_SECONDS)
                    ->withHeaders(['Accept' => 'image/jpeg,image/png,image/gif,image/webp'])
                    ->get($current);
            } catch (ConnectionException|CannotReachHost $e) {
                throw new PictureUnavailable('That picture could not be loaded.', previous: $e);
            }

            if ($response->redirect()) {
                $next = $response->header('Location');

                if (! is_string($next) || $next === '') {
                    throw new PictureUnavailable('That picture could not be loaded.');
                }

                $current = $this->absolute($current, $next);

                continue;
            }

            if (! $response->successful()) {
                throw new PictureUnavailable('That picture could not be loaded.');
            }

            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! in_array($mime, self::ALLOWED_TYPES, strict: true)) {
                throw new PictureUnavailable('That picture could not be loaded.');
            }

            $bytes = $response->body();

            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                throw new PictureUnavailable('That picture could not be loaded.');
            }

            return new EmailPicture($bytes, $mime === 'image/jpg' ? 'image/jpeg' : $mime);
        }

        throw new PictureUnavailable('That picture could not be loaded.');
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
            'host'   => strtolower((string) $parsed['host']),
            'port'   => $port,
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
