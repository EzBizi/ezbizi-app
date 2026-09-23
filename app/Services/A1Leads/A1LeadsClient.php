<?php

namespace App\Services\A1Leads;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads A1 Leads. Reads, and only reads.
 *
 * ── Why this class refuses to write, structurally ────────────────
 *
 * A1 Leads is still the only writer to A-1 Chimney's Google Business Profile.
 * Rodrigo's capability manifest states the invariant as
 * `oneWriterPerTenantCapability`, and the host enforces it two ways: the
 * consumer policy admits `GET|HEAD /api/v1/…` and nothing else, and the service
 * runs with `MIGRATION_READ_ONLY=1`, which answers any mutation with a 503.
 *
 * Neither of those is ours. So the refusal is repeated HERE, in a class that
 * has no method capable of a POST — the request is built in one private place
 * and that place hard-codes `get`. A guard that lives only on the other side of
 * a network call is a guard somebody else can relax without us noticing.
 *
 * ── The five routes, and why the list is closed ──────────────────
 *
 * `phase1:ezbizi` is allowed exactly five routes. A sixth would answer 403, so
 * naming them here turns "the policy refused you" into a mistake that cannot
 * reach the network — and the list doubles as the documentation of what this
 * integration is allowed to know.
 */
class A1LeadsClient
{
    /**
     * Everything this consumer may ask for.
     *
     * Kept as a constant rather than assembled from arguments so that a typo
     * fails here, where the message can say what the real routes are, rather
     * than as a 403 whose cause is a policy file on another service.
     */
    public const ROUTES = [
        'accounts' => '/api/v1/accounts',
        'reviews' => '/api/v1/reviews',
        'reviews_summary' => '/api/v1/reviews/summary',
        'gbp_health' => '/api/v1/gbp/health',
        'gbp_locations' => '/api/v1/gbp/locations',
    ];

    public function configured(): bool
    {
        return is_string(config('services.a1leads.api_key'))
            && config('services.a1leads.api_key') !== '';
    }

    /**
     * What the tab shows above everything else.
     *
     * Three different times, because they answer three different questions and
     * Rodrigo asked for them separately: when the snapshot was CAPTURED on his
     * machine, when this host INSTALLED it, and — the one a person actually
     * cares about — whether that is recent enough to trust.
     *
     * @return array{configured: bool, reachable: bool, captured_at: ?string, installed_at: ?string, generation: ?string, stale: bool, hours_old: ?float, because: ?string}
     */
    public function source(): array
    {
        $receipt = $this->receipt();

        $capturedAt = isset($receipt['snapshot_captured_at'])
            ? Carbon::parse($receipt['snapshot_captured_at'])
            : null;

        /*
         * Age is measured from the CAPTURE, not the install.
         *
         * A failed refresh leaves yesterday's file installed and today's clock
         * on it if you measure the wrong one — which is precisely the reading
         * that makes a stale snapshot look fresh. The capture time is a fact
         * about the data; the install time is a fact about us.
         */
        $hoursOld = $capturedAt?->diffInMinutes(now()) / 60;
        $limit = (int) config('services.a1leads.stale_after_hours', 30);

        return [
            'configured' => $this->configured(),
            'reachable' => $this->configured() && $this->reachable(),
            'captured_at' => $capturedAt?->toIso8601String(),
            'installed_at' => isset($receipt['installed_at'])
                ? Carbon::parse($receipt['installed_at'])->toIso8601String()
                : null,
            'generation' => $receipt['installed_generation'] ?? null,
            'hours_old' => $hoursOld === null ? null : round($hoursOld, 1),
            'stale' => $hoursOld !== null && $hoursOld > $limit,
            'because' => $this->configured() ? null : 'No A1 Leads key is configured for this environment.',
        ];
    }

    /** @return array<string, mixed> */
    public function reviewsSummary(): array
    {
        return $this->get('reviews_summary');
    }

    /**
     * One page of reviews, newest first.
     *
     * Paged rather than fetched whole on purpose: the snapshot holds about
     * 13,000 of them, which is 132 pages at Rodrigo's own suggested 100, and he
     * asked that reads stay under 120 a minute. A screen shows a page.
     *
     * @return array<string, mixed>
     */
    public function reviews(int $limit = 20, int $offset = 0, array $filters = []): array
    {
        return $this->get('reviews', [
            ...$this->filters($filters),
            'limit' => max(1, min($limit, 100)),
            'offset' => max(0, $offset),
        ]);
    }

    /**
     * The filters `/api/v1/reviews` understands, and no others.
     *
     * Taken from A1 Tools' own reader rather than from a specification: it is
     * the consumer that has been asking this endpoint these questions for
     * months, so this list is measured rather than hoped for. Anything else a
     * request carries is dropped here instead of being forwarded, because a
     * filter the other service ignores is worse than one it refuses - the list
     * comes back wrong and looks right.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function filters(array $filters): array
    {
        $allowed = ['city', 'location', 'rating', 'replied', 'matched', 'from', 'to', 'sort'];

        $out = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /**
     * The GBP locations, for the tab's location filter.
     *
     * A1 Tools reads its own location list from `/api/v1/reviews/locations`,
     * which is NOT one of the five routes this consumer was granted and would
     * answer 403. `/api/v1/gbp/locations` is granted and describes the same
     * listings, so the tab asks that one and the shapes are read tolerantly -
     * every field is optional and a location missing its name is dropped rather
     * than drawn as a blank row.
     *
     * @return list<array{name: string, title: ?string, address: ?string}>
     */
    public function locations(): array
    {
        $rows = $this->get('gbp_locations')['data'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['gbp_location_name'] ?? $row['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'title' => self::text($row['title'] ?? $row['location_title'] ?? null),
                'address' => self::text($row['address'] ?? $row['location_address'] ?? null),
            ];
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }

    /** @return array<string, mixed> */
    public function gbpHealth(): array
    {
        return $this->get('gbp_health');
    }

    /** @return array<string, mixed> */
    public function gbpLocations(): array
    {
        return $this->get('gbp_locations');
    }

    /**
     * Liveness, without spending a data route on it.
     *
     * `/_info` is unauthenticated by design on that service and says only that
     * the process is up. It is deliberately NOT treated as freshness: a 200
     * here with a three-week-old snapshot behind it is exactly the failure the
     * receipt exists to expose, and Rodrigo says so in as many words.
     */
    private function reachable(): bool
    {
        try {
            return Http::timeout((int) config('services.a1leads.timeout', 15))
                ->get($this->base().'/_info')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The only place a request is made, and it can only be a GET.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $route, array $query = []): array
    {
        if (! isset(self::ROUTES[$route])) {
            throw new RuntimeException(
                "A1 Leads has no route '{$route}' for this consumer. Allowed: "
                .implode(', ', array_keys(self::ROUTES)).'.'
            );
        }

        if (! $this->configured()) {
            throw new RuntimeException('No A1 Leads key is configured for this environment.');
        }

        $response = Http::timeout((int) config('services.a1leads.timeout', 15))
            // The header, never `?key=`. A secret in a URL reaches access logs,
            // Referer headers and any exception reporter that records the URL.
            ->withHeaders(['X-API-Key' => (string) config('services.a1leads.api_key')])
            ->get($this->base().self::ROUTES[$route], $query);

        if (! $response->successful()) {
            /*
             * The status is named and the body is not.
             *
             * 403 means the consumer policy does not list this route, which is
             * a configuration fact worth saying. The body could carry anything
             * the other service chose to include, and this message ends up in
             * logs.
             */
            throw new RuntimeException(
                "A1 Leads refused {$route} with HTTP {$response->status()}."
            );
        }

        return $response->json() ?? [];
    }

    private function base(): string
    {
        return rtrim((string) config('services.a1leads.base_url'), '/');
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        $path = (string) config('services.a1leads.receipt');

        if ($path === '' || ! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
