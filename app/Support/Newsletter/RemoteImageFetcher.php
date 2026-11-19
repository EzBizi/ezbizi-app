<?php

namespace App\Support\Newsletter;

use App\Models\BusinessLocation;
use App\Models\WebSite;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Reads one picture from a company's OWN website, so a starter template's
 * logo and photos can be copied into the company's newsletter library
 * (suggestion 01a1ef54 follow-up, 2026-11-18).
 *
 * This is a server-side fetch of an address a user typed, so it is built
 * against SSRF first (security notes: newsletter-image-copy-security.md):
 *
 *  - https only, port 443, no credentials in the address;
 *  - the host must be one of the company's own domains: its Web Management
 *    sites (company and branches) and its business locations' website field,
 *    each with its parent domain and that domain's www;
 *  - every address the host resolves to must be public (no private,
 *    loopback, link-local or reserved range) and the connection is pinned to
 *    the checked address, so a second DNS answer cannot swap it;
 *  - redirects are followed by hand, at most 3, and only on the same host;
 *  - the bytes must BE a PNG, JPEG, GIF or WebP (read from the content), at
 *    most 2 MB, within a 10 second timeout.
 */
class RemoteImageFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /** @var Closure(string): string[] */
    private Closure $resolver;

    /** @param  (Closure(string): string[])|null  $resolver  host → IP addresses; tests pass their own. */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $v4 = @gethostbynamel($host) ?: [];
            $v6 = [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $v6[] = $record['ipv6'];
                }
            }

            return [...$v4, ...$v6];
        };
    }

    /**
     * The company's own hosts, lower case: Web Management sites of these
     * companies (and their branches, which carry the same company_id) plus
     * their business locations' website field. Each adds its parent domain and
     * that domain's www, so hou.example.com also allows www.example.com.
     *
     * @param  string[]|null  $companyIds  null: every company in the organization
     * @return string[]
     */
    public function allowedHosts(string $organizationId, ?array $companyIds): array
    {
        $sites = WebSite::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->pluck('host')->all();
        $locations = BusinessLocation::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereNotNull('website')->pluck('website')
            ->map(fn ($w) => parse_url(str_contains((string) $w, '://') ? (string) $w : 'https://'.$w, PHP_URL_HOST))
            ->all();

        $hosts = [];
        foreach ([...$sites, ...$locations] as $host) {
            $host = strtolower(trim((string) $host, " .\t\n"));
            if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            $hosts[] = $host;
            $labels = explode('.', $host);
            if (count($labels) >= 2) {
                $parent = implode('.', array_slice($labels, -2));
                $hosts[] = $parent;
                $hosts[] = 'www.'.$parent;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The picture's bytes and extension, or a ValidationException on `url`
     * saying why it was refused.
     *
     * @param  string[]  $allowedHosts
     * @return array{bytes: string, extension: string, name: string}
     */
    public function fetch(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            $this->refuse('Only https:// picture addresses can be copied.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            $this->refuse('That picture address cannot be copied.');
        }
        if (! in_array($host, $allowedHosts, true)) {
            $this->refuse("{$host} is not one of this company’s websites, so the picture was not copied.");
        }

        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->publicAddress($host);
            $pin = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            try {
                $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 10,
                // Pinned to the address just checked (no DNS rebinding).
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pin}"]],
                'progress' => function ($total, $downloaded): void {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('too large');
                    }
                },
            ])->accept('image/*')->get($current);
            } catch (\Throwable $e) {
                $this->refuse(str_contains($e->getMessage(), 'too large')
                    ? 'That picture is larger than 2 MB, the most an email picture may be.'
                    : 'The picture could not be read from that website.');
            }

            if ($response->redirect()) {
                $next = (string) $response->header('Location');
                $next = str_starts_with($next, '/') ? "https://{$host}{$next}" : $next;
                $np = parse_url($next);
                if (($np['scheme'] ?? '') !== 'https' || strtolower((string) ($np['host'] ?? '')) !== $host) {
                    $this->refuse('The picture address redirects to another site, so it was not copied.');
                }
                $current = $next;

                continue;
            }
            if (! $response->successful()) {
                $this->refuse("The website answered {$response->status()} for that picture.");
            }

            $bytes = $response->body();
            if (strlen($bytes) > self::MAX_BYTES) {
                $this->refuse('That picture is larger than 2 MB, the most an email picture may be.');
            }
            $info = @getimagesizefromstring($bytes);
            $extension = self::TYPES[$info['mime'] ?? ''] ?? null;
            if ($info === false || $extension === null) {
                $this->refuse('That address is not a PNG, JPEG, GIF or WebP picture.');
            }

            $name = basename((string) parse_url($current, PHP_URL_PATH)) ?: 'Picture.'.$extension;

            return ['bytes' => $bytes, 'extension' => $extension, 'name' => mb_substr($name, 0, 120)];
        }

        $this->refuse('The picture address redirects too many times.');
    }

    /** One public address the host resolves to; refused if ANY answer is not public. */
    private function publicAddress(string $host): string
    {
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            $this->refuse("{$host} could not be found.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->refuse("{$host} points at a private or local network address, so it was not copied.");
            }
        }

        return $ips[0];
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['url' => $message]);
    }
}
